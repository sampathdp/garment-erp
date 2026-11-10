<?php

declare(strict_types=1);

namespace App\Modules\Garments\Http\Controllers;

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Garments\Actions\ConfirmGarmentList;
use App\Modules\Garments\Actions\EditGarmentList;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentCsv;
use App\Modules\Garments\Support\GarmentListReview;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pricing\Queries\PriceQuote;
use App\Support\Exceptions\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Time\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Name/number list of an order. Every write requires the policy (scope, permission, state)
 * AND the action re-checks, under a row lock, that the current version is still a draft.
 */
final class GarmentListController
{
    public function show(Request $request, Order $order, EditGarmentList $edit, GarmentListReview $review, PriceQuote $pricing): JsonResponse
    {
        Gate::authorize('view', $order);
        abort_unless($request->user()?->can(Permissions::GARMENT_LISTS_VIEW), 403);
        if (! $order->status->isReleased()) {
            throw ApiException::conflict('invalid_state', 'The name/number list is available after payment approval.');
        }

        /** @var User $actor */
        $actor = $request->user();
        $item = OrderItem::query()->where('order_id', $order->id)->orderBy('sort_order')->firstOrFail();
        $list = $edit->ensure($actor, $item);
        Gate::authorize('view', $list);

        return ApiResponse::data($this->present($list, $order, $item, $review, $pricing));
    }

    public function addRows(Request $request, GarmentList $list, EditGarmentList $edit): JsonResponse
    {
        Gate::authorize('update', $list);
        $data = $request->validate(['version' => ['required', 'integer'], 'rows' => ['required', 'array', 'min:1'], 'rows.*' => ['array']]);
        /** @var User $actor */
        $actor = $request->user();
        $edit->add($actor, $list, (int) $data['version'], array_values($data['rows']));

        return $this->fresh($list);
    }

    public function updateRow(Request $request, GarmentList $list, int $item, EditGarmentList $edit): JsonResponse
    {
        Gate::authorize('update', $list);
        $data = $request->validate(['version' => ['required', 'integer'], 'row' => ['required', 'array']]);
        /** @var User $actor */
        $actor = $request->user();
        $edit->update($actor, $list, (int) $data['version'], $item, $data['row']);

        return $this->fresh($list);
    }

    public function deleteRow(Request $request, GarmentList $list, int $item, EditGarmentList $edit): JsonResponse
    {
        Gate::authorize('update', $list);
        $data = $request->validate(['version' => ['required', 'integer']]);
        /** @var User $actor */
        $actor = $request->user();
        $edit->delete($actor, $list, (int) $data['version'], $item);

        return $this->fresh($list);
    }

    /** CSV import, all-or-nothing with per-row errors (mode append | replace). */
    public function import(Request $request, GarmentList $list, EditGarmentList $edit, GarmentCsv $csv): JsonResponse
    {
        Gate::authorize('update', $list);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'mode' => ['required', 'in:append,replace'],
            'file' => ['required', 'file', new AllowedDocument(DocumentCategory::ImportFile)],
        ]);
        $schema = GarmentSchema::forProductType($this->item($list)->product_type_id);
        $rows = $csv->parse((string) $request->file('file')?->getRealPath(), $schema);
        /** @var User $actor */
        $actor = $request->user();
        $edit->add($actor, $list, (int) $data['version'], $rows, $data['mode'] === 'replace');

        return $this->fresh($list);
    }

    /** CSV export of the current version (spreadsheet formula injection neutralised). */
    public function export(GarmentList $list, GarmentCsv $csv): StreamedResponse
    {
        Gate::authorize('view', $list);
        $item = $this->item($list);
        $version = GarmentListVersion::query()->findOrFail($list->current_version_id);
        $orderNo = (string) Order::query()->whereKey($item->order_id)->value('order_no');

        return response()->streamDownload(function () use ($csv, $version, $item): void {
            $csv->write($version, GarmentSchema::forProductType($item->product_type_id));
        }, "name-number-list-{$orderNo}-v{$version->version_no}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function confirm(Request $request, GarmentList $list, ConfirmGarmentList $action): JsonResponse
    {
        Gate::authorize('confirm', $list);
        $data = $request->validate(['version' => ['required', 'integer'], 'acknowledge_warnings' => ['sometimes', 'boolean']]);
        /** @var User $actor */
        $actor = $request->user();
        $invoice = $action->confirm($actor, $list, (int) $data['version'], (bool) ($data['acknowledge_warnings'] ?? false));

        return ApiResponse::data(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no, ...$this->fresh($list)->getData(true)['data']]);
    }

    /** Administrative correction after confirmation (garment_lists.unlock, audited, reason required). */
    public function correct(Request $request, GarmentList $list, ConfirmGarmentList $action): JsonResponse
    {
        Gate::authorize('unlock', $list);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        /** @var User $actor */
        $actor = $request->user();
        $action->correct($actor, $list, $data['reason']);

        return $this->fresh($list);
    }

    private function fresh(GarmentList $list): JsonResponse
    {
        $list = $list->fresh() ?? $list;
        $item = $this->item($list);
        $order = Order::query()->findOrFail($item->order_id);

        return ApiResponse::data($this->present($list, $order, $item, app(GarmentListReview::class), app(PriceQuote::class)));
    }

    private function item(GarmentList $list): OrderItem
    {
        return OrderItem::query()->select(['id', 'order_id', 'product_type_id', 'quantity'])->findOrFail($list->order_item_id);
    }

    /** @return array<string, mixed> */
    private function present(GarmentList $list, Order $order, OrderItem $item, GarmentListReview $review, PriceQuote $pricing): array
    {
        $schema = GarmentSchema::forProductType($item->product_type_id);
        $current = GarmentListVersion::query()->with(['locker:id,name', 'creator:id,name'])->findOrFail($list->current_version_id);
        $items = GarmentListItem::query()->where('garment_list_version_id', $current->id)->with('values')->orderBy('line_no')->get();
        $summary = $review->review($current, $schema, $item->quantity);

        try {
            $quote = $pricing->quote($order->page_id, $order->branch_id, $item->product_type_id, $order->material_id, BusinessDate::today(), $summary['price_groups']);
            $preview = ['lines' => $quote['lines'], 'subtotal_minor' => array_sum(array_column($quote['lines'], 'line_total_minor')), 'price_list' => $quote['price_list']->name, 'error' => null];
        } catch (ApiException $e) {
            $preview = ['lines' => [], 'subtotal_minor' => 0, 'price_list' => null, 'error' => $e->getMessage(), 'details' => $e->errors['pricing'] ?? []];
        }

        return [
            'id' => $list->id,
            'version' => $list->version,
            'order' => ['id' => $order->id, 'order_no' => $order->order_no, 'currency' => $order->currency, 'gross_quantity' => $item->quantity],
            'current_version' => [
                'id' => $current->id, 'version_no' => $current->version_no, 'status' => $current->status->value,
                'change_reason' => $current->change_reason, 'created_by' => $current->creator->name,
                'locked_by' => $current->locker?->name, 'locked_at' => $current->locked_at?->toIso8601ZuluString(),
            ],
            'schema' => $schema->toArray(),
            'items' => $items->map(fn (GarmentListItem $i): array => [
                'id' => $i->id, 'line_no' => $i->line_no, 'size_id' => $i->size_id, 'size' => $schema->sizes->get($i->size_id)?->label,
                'quantity' => $i->quantity, 'player_name' => $i->player_name, 'number' => $i->number, 'notes' => $i->notes, 'values' => $i->valueMap(),
            ])->all(),
            'review' => $summary,
            'pricing_preview' => $preview,
            'history' => GarmentListVersion::query()->where('garment_list_id', $list->id)->with(['locker:id,name', 'creator:id,name'])->orderByDesc('version_no')->get()
                ->map(fn (GarmentListVersion $v): array => [
                    'version_no' => $v->version_no, 'status' => $v->status->value, 'change_reason' => $v->change_reason, 'created_by' => $v->creator->name,
                    'locked_by' => $v->locker?->name, 'locked_at' => $v->locked_at?->toIso8601ZuluString(), 'total_quantity' => $v->total_quantity,
                ])->all(),
            'abilities' => [
                'edit' => Gate::allows('update', $list),
                'confirm' => Gate::allows('confirm', $list),
                'correct' => Gate::allows('unlock', $list),
            ],
        ];
    }
}
