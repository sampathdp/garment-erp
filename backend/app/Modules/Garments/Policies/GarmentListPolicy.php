<?php

declare(strict_types=1);

namespace App\Modules\Garments\Policies;

use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Name/number lists. Only an unlocked (draft) current version can be edited. */
final class GarmentListPolicy extends ScopedPolicy
{
    public function view(User $user, GarmentList $list): Response
    {
        return $this->decide($this->inOrderScope($user, $list), $user->can(Permissions::GARMENT_LISTS_VIEW));
    }

    public function update(User $user, GarmentList $list): Response
    {
        $status = $this->currentStatus($list);

        return $this->decide(
            $this->inOrderScope($user, $list),
            $user->can(Permissions::GARMENT_LISTS_MANAGE),
            $status === null || $status === GarmentListVersionStatus::Draft,
            'The garment list is locked. Unlock it before editing.',
        );
    }

    /** Page Owner reviews the whole list and confirms it: the current draft becomes the locked snapshot. */
    public function confirm(User $user, GarmentList $list): Response
    {
        $status = $this->currentStatus($list);

        return $this->decide(
            $this->inOrderScope($user, $list) && $user->can(Permissions::GARMENT_LISTS_VIEW),
            $user->can(Permissions::GARMENT_LISTS_CONFIRM),
            $status === GarmentListVersionStatus::Draft,
            'The garment list is already confirmed.',
        );
    }

    /** Administrative correction after confirmation (opens a new draft version, audited). */
    public function unlock(User $user, GarmentList $list): Response
    {
        return $this->decide(
            $this->inOrderScope($user, $list),
            $user->can(Permissions::GARMENT_LISTS_UNLOCK),
            $this->currentStatus($list) === GarmentListVersionStatus::Locked,
            'The garment list is not locked.',
        );
    }

    private function inOrderScope(User $user, GarmentList $list): bool
    {
        $orderId = OrderItem::query()->whereKey($list->getAttribute('order_item_id'))->value('order_id');

        return $this->orderInScope($user, $orderId === null ? null : (int) $orderId);
    }

    private function currentStatus(GarmentList $list): ?GarmentListVersionStatus
    {
        $versionId = $list->getAttribute('current_version_id');
        if ($versionId === null) {
            return null;
        }

        $status = GarmentListVersion::query()->whereKey($versionId)->value('status');

        return $status instanceof GarmentListVersionStatus ? $status : ($status === null ? null : GarmentListVersionStatus::from((string) $status));
    }
}
