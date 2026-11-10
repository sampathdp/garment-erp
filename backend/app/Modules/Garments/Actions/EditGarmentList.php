<?php

declare(strict_types=1);

namespace App\Modules\Garments\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListItemValue;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentRowValidator;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * All edits of a name/number list: add rows (one or many — bulk entry and import), edit a row,
 * delete a row, replace all rows (import). Every edit locks the list row, checks the expected
 * `version` (optimistic lock) and REQUIRES the current version to be a draft — a confirmed list
 * cannot be changed through the API even if the frontend is bypassed. Errors are per row and
 * nothing is saved partially.
 */
final class EditGarmentList
{
    public const MAX_ROWS = 2000;

    public function __construct(private readonly GarmentRowValidator $validator, private readonly AuditLogger $audit) {}

    /** Gets (or creates on first use) the order item's list with a draft version 1. */
    public function ensure(User $actor, OrderItem $item): GarmentList
    {
        return DB::transaction(function () use ($actor, $item): GarmentList {
            $list = GarmentList::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
            if ($list === null) {
                $list = GarmentList::query()->create(['order_item_id' => $item->id]);
                $version = new GarmentListVersion(['garment_list_id' => $list->id]);
                $version->forceFill(['version_no' => 1, 'status' => GarmentListVersionStatus::Draft, 'created_by' => $actor->id])->save();
                $list->forceFill(['current_version_id' => $version->id, 'version' => 1])->save();
            }

            return $list;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function add(User $actor, GarmentList $list, int $expectedVersion, array $rows, bool $replace = false): GarmentList
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new ApiException(422, 'validation_failed', 'Too many rows.', ['rows' => ['At most '.self::MAX_ROWS.' rows at a time.']]);
        }

        return DB::transaction(function () use ($actor, $list, $expectedVersion, $rows, $replace): GarmentList {
            [$list, $version, $schema] = $this->lockDraft($list, $expectedVersion);

            $normalised = [];
            $errors = [];
            foreach ($rows as $i => $row) {
                [$clean, $rowErrors] = $this->validator->validate($row, $schema);
                foreach ($rowErrors as $field => $message) {
                    $errors["rows.{$i}.{$field}"] = [$message];
                }
                $normalised[] = $clean;
            }
            if ($errors !== []) {
                throw new ApiException(422, 'validation_failed', 'Some rows are invalid. Nothing was saved.', $errors);
            }

            if ($replace) {
                GarmentListItem::query()->where('garment_list_version_id', $version->id)->delete(); // values cascade
            }
            $lineNo = $replace ? 0 : (int) GarmentListItem::query()->where('garment_list_version_id', $version->id)->max('line_no');
            foreach ($normalised as $row) {
                $this->insert($version->id, ++$lineNo, $row);
            }

            return $this->touch($actor, $list, $replace ? 'garment_lists.rows_replaced' : 'garment_lists.rows_added', ['rows' => count($normalised)]);
        });
    }

    /** @param  array<string, mixed>  $row */
    public function update(User $actor, GarmentList $list, int $expectedVersion, int $itemId, array $row): GarmentList
    {
        return DB::transaction(function () use ($actor, $list, $expectedVersion, $itemId, $row): GarmentList {
            [$list, $version, $schema] = $this->lockDraft($list, $expectedVersion);
            $item = GarmentListItem::query()->where('garment_list_version_id', $version->id)->findOrFail($itemId);

            [$clean, $errors] = $this->validator->validate($row, $schema);
            if ($errors !== []) {
                throw new ApiException(422, 'validation_failed', 'The row is invalid.', array_map(fn (string $m) => [$m], $errors));
            }

            $item->fill(['size_id' => $clean['size_id'], 'quantity' => $clean['quantity'], 'player_name' => $clean['player_name'], 'number' => $clean['number'], 'notes' => $clean['notes']])->save();
            GarmentListItemValue::query()->where('garment_list_item_id', $item->id)->delete();
            foreach ($clean['values'] as $key => $value) {
                GarmentListItemValue::query()->create(['garment_list_item_id' => $item->id, 'field_key' => $key, 'value' => $value]);
            }

            return $this->touch($actor, $list, 'garment_lists.row_updated', ['line_no' => $item->line_no]);
        });
    }

    public function delete(User $actor, GarmentList $list, int $expectedVersion, int $itemId): GarmentList
    {
        return DB::transaction(function () use ($actor, $list, $expectedVersion, $itemId): GarmentList {
            [$list, $version] = $this->lockDraft($list, $expectedVersion);
            $item = GarmentListItem::query()->where('garment_list_version_id', $version->id)->findOrFail($itemId);
            $item->delete();

            return $this->touch($actor, $list, 'garment_lists.row_deleted', ['line_no' => $item->line_no]);
        });
    }

    /** @param  array{size_id: int|null, quantity: int, player_name: string|null, number: string|null, notes: string|null, values: array<string, string>}  $row */
    public function insert(int $versionId, int $lineNo, array $row): GarmentListItem
    {
        $item = GarmentListItem::query()->create([
            'garment_list_version_id' => $versionId, 'line_no' => $lineNo, 'size_id' => $row['size_id'], 'quantity' => $row['quantity'],
            'player_name' => $row['player_name'], 'number' => $row['number'], 'notes' => $row['notes'],
        ]);
        foreach ($row['values'] as $key => $value) {
            GarmentListItemValue::query()->create(['garment_list_item_id' => $item->id, 'field_key' => $key, 'value' => $value]);
        }

        return $item;
    }

    /** @return array{0: GarmentList, 1: GarmentListVersion, 2: GarmentSchema} */
    private function lockDraft(GarmentList $list, int $expectedVersion): array
    {
        $list = GarmentList::query()->lockForUpdate()->findOrFail($list->id);
        if ($list->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The list was changed by someone else. Reload it and try again.');
        }
        $version = GarmentListVersion::query()->findOrFail($list->getAttribute('current_version_id'));
        if (! $version->isEditable()) {
            throw ApiException::conflict('list_locked', 'The garment list is confirmed and locked. An administrative correction is required to change it.');
        }
        $item = OrderItem::query()->select(['id', 'order_id', 'product_type_id'])->findOrFail($list->order_item_id);
        $order = Order::query()->select(['id', 'status'])->findOrFail($item->order_id);
        if (! $order->status->isReleased() || $order->status->isTerminal()) {
            throw ApiException::conflict('invalid_state', 'The garment list can be edited after payment approval, while the job is active.');
        }

        return [$list, $version, GarmentSchema::forProductType($item->product_type_id)];
    }

    /** @param  array<string, mixed>  $details */
    private function touch(User $actor, GarmentList $list, string $action, array $details): GarmentList
    {
        $list->forceFill(['version' => $list->version + 1])->save();
        $branchId = (int) DB::table('orders')->join('order_items', 'order_items.order_id', '=', 'orders.id')->where('order_items.id', $list->order_item_id)->value('orders.branch_id');
        $this->audit->record($action, $list, ['after' => $details], actor: $actor, branchId: $branchId);

        return $list;
    }
}
