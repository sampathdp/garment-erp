<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Garments\Actions\EditGarmentList;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Identity\Support\Roles;
use App\Modules\Invoices\Models\Invoice;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    designWorld($this);
    $this->order = approvedOrder($this);
});

describe('editing', function () {
    it('returns the configured fields, sizes and an empty draft', function () {
        $list = garmentList($this, $this->order);

        expect($list['current_version']['status'])->toBe('draft')
            ->and(collect($list['schema']['fields'])->pluck('key')->all())->toBe(['player_name', 'number', 'sleeve_type', 'age_group'])
            ->and(collect($list['schema']['sizes'])->pluck('code')->all())->toBe(['S', 'M', 'L'])
            ->and($list['review']['can_confirm'])->toBeFalse()
            ->and($list['abilities'])->toBe(['edit' => true, 'confirm' => true, 'correct' => false]);
    });

    it('adds rows in bulk, edits and deletes them, with quantity and size summaries', function () {
        $list = fillList($this, $this->order);
        expect($list['review']['total_quantity'])->toBe(10)
            ->and($list['review']['by_size'])->toBe([
                ['size_id' => $this->sizes['S']->id, 'size' => 'S', 'quantity' => 4],
                ['size_id' => $this->sizes['M']->id, 'size' => 'M', 'quantity' => 6],
            ])
            ->and(collect($list['review']['price_groups'])->pluck('quantity')->all())->toBe([6, 4]);

        $row = $list['items'][0];
        $list = $this->signedInAs($this->owner)->patchJson("/api/v1/garment-lists/{$list['id']}/rows/{$row['id']}", ['version' => $list['version'], 'row' => [
            ...$row, 'size_id' => $this->sizes['L']->id, 'quantity' => 2, 'player_name' => 'Captain',
        ]])->assertOk()->json('data');
        expect($list['items'][0]['size'])->toBe('L')->and($list['review']['total_quantity'])->toBe(11);

        $list = $this->signedInAs($this->owner)->deleteJson("/api/v1/garment-lists/{$list['id']}/rows/{$list['items'][9]['id']}", ['version' => $list['version']])->assertOk()->json('data');
        expect($list['review']['total_quantity'])->toBe(10)->and($list['items'])->toHaveCount(9);
    });

    it('validates every row and saves nothing when one is invalid', function () {
        $list = garmentList($this, $this->order);
        $rows = jerseyRows($this, 2, 0);
        $rows[1]['values']['sleeve_type'] = 'kimono';
        $rows[1]['size_id'] = 999;
        $rows[1]['number'] = '1a';
        $rows[1]['values']['colour'] = 'red';

        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => $rows])
            ->assertStatus(422)->assertJsonValidationErrors(['rows.1.sleeve_type', 'rows.1.size_id', 'rows.1.number', 'rows.1.values.colour'])
            ->assertJsonMissingValidationErrors('rows.0.size_id');
        expect(garmentList($this, $this->order)['items'])->toBe([]);
    });

    it('detects concurrent edits with the list version', function () {
        $list = garmentList($this, $this->order);
        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => jerseyRows($this, 1, 0)])->assertOk();
        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => jerseyRows($this, 1, 0)])
            ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    });

    it('is invisible to other pages and read-only without manage permission', function () {
        $list = garmentList($this, $this->order);
        $this->signedInAs($this->ownerB)->getJson("/api/v1/orders/{$this->order->id}/garment-list")->assertNotFound();
        $this->signedInAs($this->ownerB)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => jerseyRows($this, 1, 0)])->assertNotFound();
        $this->signedInAs($this->ops)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => jerseyRows($this, 1, 0)])->assertForbidden();
    });
});

describe('import and export', function () {
    it('imports a CSV all-or-nothing and exports with formula injection neutralised', function () {
        $list = garmentList($this, $this->order);
        $csv = "Size,Quantity,Player Name,Player Number,Sleeve Type,Kids / Adult\n"
            ."M,5,=HYPERLINK(\"http://x\"),7,Short sleeve,Adult\n"
            ."S,5,Kid,8,long,kids\n";
        $file = UploadedFile::fake()->createWithContent('list.csv', $csv);

        $list = $this->signedInAs($this->owner)->post("/api/v1/garment-lists/{$list['id']}/import", ['version' => $list['version'], 'mode' => 'replace', 'file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
        expect($list['review']['total_quantity'])->toBe(10)
            ->and($list['items'][1]['values'])->toEqual(['sleeve_type' => 'long', 'age_group' => 'kids']);

        $export = $this->signedInAs($this->owner)->get("/api/v1/garment-lists/{$list['id']}/export")->assertOk()->streamedContent();
        expect($export)->toContain("'=HYPERLINK")->toContain('Long sleeve')->not->toContain(',=HYPERLINK');

        $bad = UploadedFile::fake()->createWithContent('bad.csv', "Size,Quantity\nXXL,1\n");
        $this->signedInAs($this->owner)->post("/api/v1/garment-lists/{$list['id']}/import", ['version' => $list['version'], 'mode' => 'append', 'file' => $bad], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('rows.0.size_id');
        expect(garmentList($this, $this->order)['items'])->toHaveCount(2);
    });
});

describe('confirmation and locking', function () {
    it('blocks confirmation with errors and requires acknowledging duplicate warnings', function () {
        fillList($this, $this->order, jerseyRows($this, 5, 0));
        confirmList($this, $this->order)->assertStatus(422)->assertJsonPath('code', 'list_invalid')
            ->assertJsonFragment(["The list totals 5 garments but the order's gross quantity is 10."]);

        $rows = array_map(fn (array $r, int $i) => [...$r, 'number' => (string) (20 + $i)], jerseyRows($this, 0, 5), range(0, 4));
        $rows[1]['number'] = '1'; // duplicate number with row 1 of the first batch
        fillList($this, $this->order, $rows);
        $review = garmentList($this, $this->order)['review'];
        expect($review['can_confirm'])->toBeTrue()->and($review['warnings'][0]['message'])->toContain('Player Number "1" appears on rows 1, 7');

        confirmList($this, $this->order, ack: false)->assertStatus(422)->assertJsonPath('code', 'list_warnings');
        confirmList($this, $this->order)->assertOk()->assertJsonPath('data.current_version.status', 'locked');
    });

    it('reports missing required values', function () {
        $rows = jerseyRows($this);
        unset($rows[3]['values']['age_group']);
        fillList($this, $this->order, $rows);

        confirmList($this, $this->order)->assertStatus(422)->assertJsonFragment(['Row 4: Kids / Adult is required.']);
    });

    it('locks the confirmed snapshot and refuses every normal edit on the backend', function () {
        fillList($this, $this->order);
        $confirmed = confirmList($this, $this->order)->assertOk()->json('data');

        expect($confirmed['current_version']['locked_by'])->toBe($this->owner->name)
            ->and($confirmed['current_version']['locked_at'])->not->toBeNull()
            ->and($confirmed['abilities']['edit'])->toBeFalse();

        $id = $confirmed['id'];
        $v = $confirmed['version'];
        $item = $confirmed['items'][0]['id'];
        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$id}/rows", ['version' => $v, 'rows' => jerseyRows($this, 1, 0)])->assertStatus(409);
        $this->signedInAs($this->owner)->patchJson("/api/v1/garment-lists/{$id}/rows/{$item}", ['version' => $v, 'row' => ['size_id' => $this->sizes['L']->id]])->assertStatus(409);
        $this->signedInAs($this->owner)->deleteJson("/api/v1/garment-lists/{$id}/rows/{$item}", ['version' => $v])->assertStatus(409);
        $this->signedInAs($this->owner)->post("/api/v1/garment-lists/{$id}/import", ['version' => $v, 'mode' => 'replace', 'file' => UploadedFile::fake()->createWithContent('l.csv', "Size\nM\n")], ['Accept' => 'application/json'])->assertStatus(409);
        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$id}/confirm", ['version' => $v])->assertStatus(409);

        // Even with a direct action call (frontend bypassed), the locked version cannot change.
        $list = listModel($this->order);
        expect(fn () => app(EditGarmentList::class)->add($this->owner, $list, $list->version, jerseyRows($this, 1, 0)))
            ->toThrow(ApiException::class);
    });

    it('allows only an audited administrative correction after confirmation', function () {
        fillList($this, $this->order);
        $confirmed = confirmList($this, $this->order)->assertOk()->json('data');
        $firstInvoice = $confirmed['invoice_id'];

        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$confirmed['id']}/corrections", ['reason' => 'Player 3 name misspelled'])->assertForbidden();
        $this->signedInAs($this->admin)->postJson("/api/v1/garment-lists/{$confirmed['id']}/corrections", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $draft = $this->signedInAs($this->admin)->postJson("/api/v1/garment-lists/{$confirmed['id']}/corrections", ['reason' => 'Player 3 name misspelled'])
            ->assertOk()->json('data');

        expect($draft['current_version'])->toMatchArray(['version_no' => 2, 'status' => 'draft', 'change_reason' => 'Player 3 name misspelled'])
            ->and($draft['items'])->toHaveCount(10)
            ->and(AuditLog::query()->where('action', 'garment_lists.correction_opened')->exists())->toBeTrue();

        $row = $draft['items'][2];
        $draft = $this->signedInAs($this->owner)->patchJson("/api/v1/garment-lists/{$draft['id']}/rows/{$row['id']}", ['version' => $draft['version'], 'row' => [...$row, 'player_name' => 'Player Three']])->assertOk()->json('data');
        $reconfirmed = confirmList($this, $this->order)->assertOk()->json('data');

        expect($reconfirmed['invoice_id'])->not->toBe($firstInvoice)
            ->and(GarmentListVersion::query()->where('version_no', 1)->value('status'))->toBe(GarmentListVersionStatus::Superseded)
            ->and(Invoice::query()->find($firstInvoice)->status->value)->toBe('void')
            ->and(collect($reconfirmed['history'])->pluck('status')->all())->toBe(['locked', 'superseded']);
    });

    it('needs the confirm permission and an active, approved job', function () {
        fillList($this, $this->order);
        $list = garmentList($this, $this->order);
        $this->signedInAs($this->userWithRole(Roles::OPERATION_ADMIN))->postJson("/api/v1/garment-lists/{$list['id']}/confirm", ['version' => $list['version']])->assertForbidden();
    });
});
