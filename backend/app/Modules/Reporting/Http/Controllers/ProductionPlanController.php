<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Queries\ProductionPlanQuery;
use App\Support\Http\ApiResponse;
use App\Support\Time\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Consolidated production plan of all pages (production_plan.view / production_plan.view_all_pages).
 * See ProductionPlanQuery for what is counted and what each viewer may see.
 */
final class ProductionPlanController
{
    public function show(Request $request, ProductionPlanQuery $plan): JsonResponse
    {
        Gate::authorize('production-plan-view');
        $data = $request->validate([
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'], // order and span checked below (from may default to today)
        ]);

        $today = BusinessDate::today();
        $from = isset($data['filter']['from']) ? BusinessDate::parse((string) $data['filter']['from']) : $today;
        $to = isset($data['filter']['to']) ? BusinessDate::parse((string) $data['filter']['to']) : $from->addDays(ProductionPlanQuery::DEFAULT_DAYS);
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['filter.to' => 'The end date must be on or after the start date.']);
        }
        if ($from->diffInDays($to) > ProductionPlanQuery::MAX_DAYS) {
            throw ValidationException::withMessages(['filter.to' => 'The date range can be at most '.ProductionPlanQuery::MAX_DAYS.' days.']);
        }

        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data($plan->plan($user, $from, $to));
    }
}
