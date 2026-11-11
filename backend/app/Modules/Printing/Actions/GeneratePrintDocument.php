<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\PrintDocument;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Queries\PrintJobCard;
use App\Support\Exceptions\ApiException;

/**
 * Generates the printable Job Card / name/number list (server-rendered, escaped HTML) and audits
 * every print. The controller authorises with the printDocuments policy; this Action re-checks
 * the state on fresh data, so printing can never happen before the sample is approved even if
 * the job changed between the two checks.
 */
final class GeneratePrintDocument
{
    public function __construct(private readonly PrintJobCard $documents, private readonly AuditLogger $audit) {}

    public function handle(User $actor, PrintJob $job, PrintDocument $document): string
    {
        $job = PrintJob::query()->findOrFail($job->id);
        if (! $job->status->allowsDocumentPrinting()) {
            throw ApiException::conflict('document_printing_locked', 'The Job Card and the name/number list can be printed only after the printing sample is approved.');
        }

        $html = match ($document) {
            PrintDocument::JobCard => view('printing.job-card', ['card' => $this->documents->jobCard($job), 'printedBy' => $actor->name, 'printedAt' => now()])->render(),
            PrintDocument::NameList => view('printing.name-list', ['list' => $this->documents->nameList($job), 'jobNo' => (string) $job->order()->value('order_no'), 'printedBy' => $actor->name, 'printedAt' => now()])->render(),
        };

        $this->audit->record('printing.document_printed', $job, ['after' => ['document' => $document->value, 'status' => $job->status->value]], actor: $actor);

        return $html;
    }
}
