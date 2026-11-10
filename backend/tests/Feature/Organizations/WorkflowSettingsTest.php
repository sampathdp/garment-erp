<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Roles;

beforeEach(fn () => designWorld($this));

it('lets only the Super Admin configure the responsible designers and the correction limit', function () {
    $this->signedInAs($this->owner)->getJson('/api/v1/settings/workflow')->assertForbidden();
    $this->signedInAs($this->owner)->putJson('/api/v1/settings/workflow', ['default_designer_id' => null, 'default_bulk_designer_id' => null, 'max_correction_rounds' => 9])->assertForbidden();

    $other = $this->userWithRole(Roles::DESIGN_DESIGNER);
    $this->signedInAs($this->admin)->getJson('/api/v1/settings/workflow')->assertOk()
        ->assertJsonPath('data.default_designer_id', $this->designer->id)->assertJsonPath('data.max_correction_rounds', 3)
        ->assertJsonFragment(['id' => $other->id, 'name' => $other->name]);

    $this->signedInAs($this->admin)->putJson('/api/v1/settings/workflow', ['default_designer_id' => $this->owner->id, 'default_bulk_designer_id' => null, 'max_correction_rounds' => 2])
        ->assertStatus(422)->assertJsonValidationErrors('default_designer_id');
    $this->signedInAs($this->admin)->putJson('/api/v1/settings/workflow', ['default_designer_id' => $other->id, 'default_bulk_designer_id' => $this->bulkDesigner->id, 'max_correction_rounds' => 2])
        ->assertOk()->assertJsonPath('data.default_designer_id', $other->id)->assertJsonPath('data.max_correction_rounds', 2);

    expect(submitJobDetails($this, approvedOrder($this))->designer_id)->toBe($other->id);
});

it('manages garment master data and fabric types with the catalogue permission only', function () {
    $this->signedInAs($this->owner)->getJson('/api/v1/garment-setup')->assertOk()->assertJsonFragment(['code' => 'sleeve_type']);
    $this->signedInAs($this->owner)->postJson('/api/v1/garment-setup/option-sets', ['code' => 'collar', 'name' => 'Collars'])->assertForbidden();

    $set = $this->signedInAs($this->admin)->postJson('/api/v1/garment-setup/option-sets', ['code' => 'collar', 'name' => 'Collars'])->assertCreated()->json('data.id');
    $this->signedInAs($this->admin)->postJson("/api/v1/garment-setup/option-sets/{$set}/options", ['code' => 'v_neck', 'label' => 'V neck'])->assertCreated();
    $this->signedInAs($this->admin)->postJson('/api/v1/garment-setup/fields', ['product_type_id' => $this->jersey->id, 'key' => 'collar', 'label' => 'Collar', 'input_type' => 'select'])
        ->assertStatus(422)->assertJsonValidationErrors('garment_option_set_id');
    $this->signedInAs($this->admin)->postJson('/api/v1/garment-setup/fields', ['product_type_id' => $this->jersey->id, 'key' => 'collar', 'label' => 'Collar', 'input_type' => 'select', 'garment_option_set_id' => $set, 'is_required' => true])
        ->assertCreated();
    $this->signedInAs($this->admin)->postJson('/api/v1/materials', ['code' => 'mesh', 'name' => 'Mesh'])->assertCreated();

    $order = approvedOrder($this);
    expect(collect(garmentList($this, $order)['schema']['fields'])->pluck('key')->all())->toContain('collar');
});
