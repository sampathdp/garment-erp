<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobEvent;
use App\Modules\Cutting\Models\CuttingJobQuantity;
use App\Modules\Cutting\Support\CuttingNotifications;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Pricing\Models\Size;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Opens the cutting job of a printed job — called by RecordPrintGatePass in the same
 * transaction (order + printing job already locked), once printing is completed AND the gate
 * pass details are recorded. Idempotent: at most one cutting job per printing job (UNIQUE
 * print_job_id), so a second gate pass never creates a second cutting task.
 *
 * The expected quantities per size come from the locked name/number list the printing job was
 * made from. The order moves printing → cutting.
 */
final class OpenCuttingJob
{
    public function __construct(private readonly TransitionOrder $transition, private readonly CuttingNotifications $notify, private readonly AuditLogger $audit) {}

    public function handle(PrintJob $printJob, Order $order, User $actor): CuttingJob
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A cutting job is opened inside the gate pass transaction.');
        }

        $existing = CuttingJob::query()->where('print_job_id', $printJob->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($printJob->status !== PrintJobStatus::PrintingCompleted || $order->status !== OrderStatus::Printing) {
            throw ApiException::conflict('printing_not_completed', 'A cutting job is available only after printing is completed and the gate pass is recorded.');
        }

        $rows = $this->expectedBySize((int) $printJob->getAttribute('garment_list_version_id'));
        $total = array_sum(array_column($rows, 'expected_quantity'));
        if ($total < 1) {
            throw ApiException::conflict('list_missing', 'The confirmed name/number list of this job is empty; it cannot be cut.');
        }

        $job = new CuttingJob(['order_id' => $order->id, 'order_item_id' => $printJob->order_item_id, 'print_job_id' => $printJob->id, 'planned_quantity' => $total]);
        $job->forceFill(['branch_id' => $order->branch_id, 'status' => CuttingJobStatus::PendingAssignment, 'cut_quantity' => 0, 'version' => 1])->save();
        foreach ($rows as $i => $row) {
            CuttingJobQuantity::query()->create(['cutting_job_id' => $job->id, 'size_id' => $row['size_id'], 'size_label' => $row['size_label'], 'sort_order' => $i, 'expected_quantity' => $row['expected_quantity']]);
        }

        CuttingJobEvent::query()->create(['cutting_job_id' => $job->id, 'from_status' => null, 'to_status' => CuttingJobStatus::PendingAssignment, 'note' => 'Printing completed and gate pass recorded.', 'actor_id' => $actor->id]);
        $this->transition->handle($order, OrderStatus::Cutting, $actor, 'Printing completed and gate pass recorded.');
        $this->audit->record('cutting.job_opened', $job, ['after' => ['status' => CuttingJobStatus::PendingAssignment->value, 'print_job_id' => $printJob->id, 'planned_quantity' => $total]], actor: $actor, branchId: $order->branch_id);
        $this->notify->ready($job, $order, $actor);

        return $job;
    }

    /** @return list<array{size_id: int|null, size_label: string, expected_quantity: int}> in size chart order */
    private function expectedBySize(int $listVersionId): array
    {
        $sums = GarmentListItem::query()->where('garment_list_version_id', $listVersionId)
            ->selectRaw('size_id, SUM(quantity) AS qty')->groupBy('size_id')->get()
            ->mapWithKeys(fn ($r) => [(int) ($r->getAttribute('size_id') ?? 0) => (int) $r->getAttribute('qty')]);
        $sizes = Size::query()->whereKey($sums->keys()->filter()->all())->get(['id', 'label', 'sort_order'])->keyBy('id');

        return $sums->map(fn (int $qty, int $sizeId): array => [
            'size_id' => $sizeId === 0 ? null : $sizeId,
            'size_label' => $sizeId === 0 ? 'No size' : (string) ($sizes->get($sizeId)?->getAttribute('label') ?? "Size {$sizeId}"),
            'expected_quantity' => $qty,
            'sort' => $sizeId === 0 ? PHP_INT_MAX : (int) ($sizes->get($sizeId)?->getAttribute('sort_order') ?? 0),
        ])->filter(fn (array $r) => $r['expected_quantity'] > 0)->sortBy('sort')->values()
            ->map(fn (array $r) => ['size_id' => $r['size_id'], 'size_label' => $r['size_label'], 'expected_quantity' => $r['expected_quantity']])->all();
    }
}
