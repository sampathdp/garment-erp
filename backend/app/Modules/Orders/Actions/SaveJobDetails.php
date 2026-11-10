<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Design\Actions\CreateDesignTask;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Additional order information, entered by the Page Owner after payment approval
 * (OrderPolicy::manageJobDetails checks the stage): fabric type, material details, design
 * instructions, custom notes, design-instruction attachment and overall remarks.
 * `submit()` creates the design task (once) and moves the order to design_in_progress.
 */
final class SaveJobDetails
{
    public const FIELDS = ['material_id', 'material_details', 'design_instructions', 'custom_notes', 'overall_remarks'];

    public function __construct(
        private readonly StoreDocument $documents,
        private readonly CreateDesignTask $designTasks,
        private readonly TransitionOrder $transition,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $input  validated: version + any of FIELDS */
    public function save(User $actor, Order $order, array $input, ?UploadedFile $attachment): Order
    {
        $stored = $attachment !== null ? $this->documents->put($attachment, DocumentCategory::DesignInstruction) : null;

        try {
            return DB::transaction(function () use ($actor, $order, $input, $stored): Order {
                $order = $this->lock($order, (int) $input['version']);

                $changes = array_intersect_key($input, array_flip(self::FIELDS));
                $before = $order->only(array_keys($changes));
                $order->forceFill($changes);
                if ($stored !== null) {
                    $document = $this->documents->record($stored, DocumentCategory::DesignInstruction, $actor, $order->branch_id);
                    $order->forceFill(['design_attachment_document_id' => $document->id]);
                }
                $dirty = $order->getDirty();
                if ($dirty === []) {
                    return $order;
                }
                $order->forceFill(['version' => $order->version + 1])->save();
                $this->audit->record('orders.job_details_updated', $order, ['before' => array_intersect_key($before, $dirty), 'after' => $dirty], actor: $actor);

                return $order;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->documents->discard($stored);
            }
            throw $e;
        }
    }

    public function submit(User $actor, Order $order, int $version): Order
    {
        return DB::transaction(function () use ($actor, $order, $version): Order {
            $order = $this->lock($order, $version);
            if ($order->status !== OrderStatus::Confirmed) {
                throw ApiException::conflict('invalid_state', 'Job details can be submitted once, after payment approval.');
            }

            $missing = [];
            if ($order->material_id === null) {
                $missing['material_id'] = ['Select the fabric type.'];
            }
            if (trim((string) $order->design_instructions) === '') {
                $missing['design_instructions'] = ['Enter the design instructions.'];
            }
            if ($missing !== []) {
                throw new ApiException(422, 'validation_failed', 'Complete the required job details first.', $missing);
            }

            $order->forceFill(['details_submitted_at' => now(), 'details_submitted_by' => $actor->id])->save();
            $task = $this->designTasks->handle($order, $actor);
            $this->transition->handle($order, OrderStatus::DesignInProgress, $actor, 'Job details submitted; design task created.');
            $this->audit->record('orders.job_details_submitted', $order, ['after' => ['design_task_id' => $task->id]], actor: $actor);

            return $order;
        });
    }

    private function lock(Order $order, int $version): Order
    {
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);
        if ($order->version !== $version) {
            throw ApiException::conflict('version_conflict', 'This order was changed by someone else. Reload it and try again.');
        }
        if (! $order->acceptsJobDetails()) {
            throw ApiException::conflict('invalid_state', 'Job details can be added after payment approval and until the design is approved.');
        }

        return $order;
    }
}
