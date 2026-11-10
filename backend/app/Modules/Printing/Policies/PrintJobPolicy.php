<?php

declare(strict_types=1);

namespace App\Modules\Printing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Printing: Operation Admins manage jobs and upload samples; Page Owners approve samples. */
final class PrintJobPolicy extends ScopedPolicy
{
    public function view(User $user, PrintJob $job): Response
    {
        return $this->allowScoped($user, $job, Permissions::PRINTING_VIEW);
    }

    public function manage(User $user, PrintJob $job): Response
    {
        return $this->decide(
            $job->isVisibleTo($user),
            $user->can(Permissions::PRINTING_MANAGE),
            ! in_array($job->status, [PrintJobStatus::Printed, PrintJobStatus::Invalidated], true),
            'This print job is closed.',
        );
    }

    public function uploadSample(User $user, PrintJob $job): Response
    {
        return $this->decide(
            $job->isVisibleTo($user),
            $user->can(Permissions::PRINTING_UPLOAD_SAMPLE),
            in_array($job->status, [PrintJobStatus::Preparing, PrintJobStatus::SampleReview], true),
            'Samples can only be uploaded while the job is being prepared or reviewed.',
        );
    }

    public function approveSample(User $user, PrintJob $job): Response
    {
        return $this->decide(
            $job->isVisibleTo($user),
            $user->can(Permissions::PRINTING_APPROVE_SAMPLE),
            $job->status === PrintJobStatus::SampleReview,
            'There is no printing sample awaiting approval.',
        );
    }
}
