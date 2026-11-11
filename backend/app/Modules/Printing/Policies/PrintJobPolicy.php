<?php

declare(strict_types=1);

namespace App\Modules\Printing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Printing jobs (Phase 06). Order of checks: scope (404) → permission (403) → state (409).
 *
 *  - Operation Admins send jobs for printing, upload samples, run physical printing and record
 *    gate passes. Page Owners of the order's page approve or reject samples.
 *  - Viewing the Job Card and the name/number list needs printing.view; PRINTING them is a
 *    separate ability (printDocuments) that the server refuses until the sample is approved.
 *
 * The Actions re-check every state condition under the row locks.
 */
final class PrintJobPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PRINTING_VIEW);
    }

    public function view(User $user, PrintJob $job): Response
    {
        return $this->allowScoped($user, $job, Permissions::PRINTING_VIEW);
    }

    public function send(User $user, PrintJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::PRINTING_MANAGE),
            $job->status === PrintJobStatus::ReadyForPrinting, 'Only a job that is ready for printing can be sent for printing.');
    }

    public function uploadSample(User $user, PrintJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::PRINTING_UPLOAD_SAMPLE),
            $job->status->acceptsSample(), 'A sample can be uploaded after the job is sent for printing, or after a sample was rejected.');
    }

    /** Approve or reject the pending sample: printing.approve_sample AND Page Owner of the page. */
    public function reviewSample(User $user, PrintJob $job): Response
    {
        $response = $this->decide($this->visible($user, $job), $user->can(Permissions::PRINTING_APPROVE_SAMPLE),
            $job->status === PrintJobStatus::SamplePendingApproval, 'There is no printing sample awaiting approval.');

        if ($response->allowed() && ! $user->hasAllPageAccess()) {
            $pageId = Order::query()->whereKey($job->order_id)->value('page_id');
            if (! $user->ownsPage($pageId === null ? null : (int) $pageId)) {
                return Response::deny('Only a Page Owner of this page can review the printing sample.', 'not_page_owner');
            }
        }

        return $response;
    }

    /** Physical printing may start only after the Page Owner approved the sample. */
    public function startPrinting(User $user, PrintJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::PRINTING_MANAGE),
            $job->status === PrintJobStatus::SampleApproved, 'Physical printing can start only after the printing sample is approved.');
    }

    public function completePrinting(User $user, PrintJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::PRINTING_MANAGE),
            $job->status === PrintJobStatus::PhysicalPrinting, 'Only physical printing in progress can be completed.');
    }

    /**
     * Generating the printable Job Card / name/number list. Viewing is always possible with
     * printing.view; printing only from the approved sample onwards (409 document_printing_locked).
     */
    public function printDocuments(User $user, PrintJob $job): Response
    {
        if (! $this->visible($user, $job)) {
            return Response::denyAsNotFound();
        }
        if (! $user->can(Permissions::PRINTING_PRINT_DOCUMENTS)) {
            return Response::deny();
        }

        return $job->status->allowsDocumentPrinting() ? Response::allow()
            : $this->conflict('The Job Card and the name/number list can be printed only after the printing sample is approved.', 'document_printing_locked');
    }

    /** Gate pass details are recorded after physical printing is completed. */
    public function recordGatePass(User $user, PrintJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::PRINTING_RECORD_GATE_PASS),
            $job->status === PrintJobStatus::PrintingCompleted, 'Gate pass details can be recorded only after physical printing is completed.');
    }

    /** Scope first (404), then the view permission: users who cannot view printing never learn the job exists. */
    private function visible(User $user, PrintJob $job): bool
    {
        return $job->isVisibleTo($user) && $user->can(Permissions::PRINTING_VIEW);
    }
}
