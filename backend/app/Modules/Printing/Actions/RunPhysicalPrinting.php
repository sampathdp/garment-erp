<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Actions\IssueOrderInvoice;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Actions\ApplyInvoiceTotals;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pricing\Queries\PriceQuote;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\SampleDecision;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Support\PrintingWorkflow;
use App\Modules\Printing\Support\PrintPricing;
use App\Support\Exceptions\ApiException;
use App\Support\Time\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Physical printing, run by the Operation Admin:
 *  - start():    only from an APPROVED sample (re-checked under the lock, including the approved
 *                sample row itself) → physical_printing.
 *  - complete(): records the printed quantity (per price group of the locked list) and notes →
 *                printing_completed. In the same transaction the job is priced on the printed
 *                quantities with the page price rules, the invoice is issued (immutable snapshot)
 *                and becomes the order's total (client decision 2026-11-25). A missing price
 *                blocks completion (409 pricing_missing): an invoice is never issued unpriced.
 */
final class RunPhysicalPrinting
{
    public function __construct(
        private readonly PrintingWorkflow $workflow,
        private readonly PrintPricing $printPricing,
        private readonly PriceQuote $pricing,
        private readonly IssueOrderInvoice $invoices,
        private readonly ApplyInvoiceTotals $totals,
        private readonly Notify $notify,
    ) {}

    public function start(User $actor, PrintJob $job, int $expectedVersion, ?string $notes): PrintJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $notes): PrintJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status !== PrintJobStatus::SampleApproved) {
                throw ApiException::conflict('sample_not_approved', 'Physical printing can start only after the printing sample is approved.');
            }
            $approved = PrintSample::query()->whereKey($job->getAttribute('approved_sample_id'))->where('print_job_id', $job->id)->first(['id', 'decision']);
            if ($approved?->decision !== SampleDecision::Approved) {
                throw ApiException::conflict('sample_not_approved', 'Physical printing can start only after the printing sample is approved.');
            }
            $this->workflow->ensureOrderStage($job, $order);

            $job->forceFill(['printing_started_by' => $actor->id, 'printing_started_at' => now()]);
            $this->workflow->transition($job, $order, PrintJobStatus::PhysicalPrinting, $actor, 'printing.physical_started', $notes);
            $this->workflow->save($job);

            return $job;
        });
    }

    /** @param  array<string, int>|null  $printedByGroup  group label => printed quantity; null = printed exactly as listed */
    public function complete(User $actor, PrintJob $job, int $expectedVersion, ?array $printedByGroup, ?int $printedTotal, ?string $notes): PrintJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $printedByGroup, $printedTotal, $notes): PrintJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status !== PrintJobStatus::PhysicalPrinting) {
                throw ApiException::conflict('invalid_state', 'Only physical printing in progress can be completed.');
            }
            $this->workflow->ensureOrderStage($job, $order);

            $printed = $this->printPricing->printedGroups($job, $printedByGroup, $printedTotal);
            $productTypeId = (int) OrderItem::query()->whereKey($job->getAttribute('order_item_id'))->value('product_type_id');
            $pricingDate = BusinessDate::today();
            $quote = $this->pricing->quote($order->page_id, $order->branch_id, $productTypeId, $order->material_id, $pricingDate, $printed['groups']);

            $job->forceFill(['printed_quantity' => $printed['total'], 'completion_notes' => $notes, 'printing_completed_by' => $actor->id, 'printing_completed_at' => now()]);
            $this->workflow->transition($job, $order, PrintJobStatus::PrintingCompleted, $actor, 'printing.completed', $notes, [
                'printed_quantity' => $printed['total'], 'ordered_quantity' => $order->total_quantity,
                'printed_groups' => array_map(fn (array $g) => ['label' => $g['label'], 'quantity' => $g['quantity']], $printed['groups']),
            ]);
            $this->workflow->save($job);

            $invoice = $this->invoices->handle($actor, $order, $productTypeId, $quote['price_list']->id, (int) $job->getAttribute('garment_list_version_id'), $pricingDate, $quote['lines']);
            $this->totals->handle($actor, $order, $invoice->only(['subtotal_minor', 'discount_minor', 'tax_minor', 'delivery_fee_minor', 'total_minor']));

            $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id)->merge($r->withPermission('payments.verify', $order->branch_id, $order->page_id)),
                new WorkflowNotification('invoice.issued', 'Invoice issued', "Invoice {$invoice->invoice_no} was issued for job {$order->order_no} after printing ({$printed['total']} pcs).", "/orders/view/?id={$order->id}", ['order_id' => $order->id, 'invoice_id' => $invoice->id]), $actor);

            return $job;
        });
    }
}
