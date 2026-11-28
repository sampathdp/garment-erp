"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { PageHeader } from "@/components/ui/form-controls";
import { RequirePermission } from "@/components/ui/record-page";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { formatBusinessDate } from "@/lib/format";
import { useProductionPlan, type PlanFilters } from "./api";
import {
  PlanLegend,
  ProductionPlanBoard,
  QuantityChip,
} from "./components/plan-board";

/**
 * Consolidated production plan of all pages. Page owners see the combined load with their own
 * pages named and the rest as "Other pages"; production_plan.view_all_pages names every page.
 * The server decides what is named; this screen only renders it.
 */
export function ProductionPlanScreen() {
  const [filters, setFilters] = useState<PlanFilters>({ from: "", to: "" });
  const query = useProductionPlan(filters);
  const plan = query.data;

  return (
    <RequirePermission
      anyOf={["production_plan.view", "production_plan.view_all_pages"]}
    >
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Production plan"
          description={
            plan?.page_names === "all"
              ? "Combined load of every page by due date and category, with the quantity of each page."
              : "Combined load of every page by due date and category. Check the daily load before accepting new orders. Other pages are shown as one total."
          }
        />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:items-end lg:justify-between">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
            <TextField
              label="Due from"
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              hint="Empty = today, overdue jobs included"
            />
            <TextField
              label="Due to"
              type="date"
              value={filters.to}
              onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              hint="Empty = 60 days ahead"
            />
            {(filters.from || filters.to) && (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setFilters({ from: "", to: "" })}
              >
                Reset
              </Button>
            )}
          </div>
          {plan && (
            <div className="flex items-center gap-2 text-sm">
              <span className="text-muted">
                {formatBusinessDate(plan.from)} – {formatBusinessDate(plan.to)}:
              </span>
              <QuantityChip kind="temporary" value={plan.totals.temporary} />
              <QuantityChip kind="confirmed" value={plan.totals.confirmed} />
              <span className="font-semibold tabular-nums text-ink">
                {plan.totals.total} pcs
              </span>
            </div>
          )}
        </div>
        <PlanLegend />
        {query.isPending ? (
          <LoadingState />
        ) : query.error ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : (
          plan && <ProductionPlanBoard plan={plan} />
        )}
      </div>
    </RequirePermission>
  );
}
