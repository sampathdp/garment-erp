import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api-client";

/** Temporary = estimate (yellow), confirmed = design completed and list locked (red). */
export interface PlanQuantities {
  temporary: number;
  confirmed: number;
  total: number;
}

export interface PlanPage {
  id: number;
  code: string;
  name: string;
}

export interface PlanPageLoad extends PlanQuantities {
  page: PlanPage;
  is_own: boolean;
}

export interface PlanCategory extends PlanQuantities {
  product_type: { id: number; name: string };
  /** Every page (view_all_pages) or only the viewer's own pages. */
  pages: PlanPageLoad[];
  /** Anonymous load of every other page; null when every page is named. */
  other_pages: PlanQuantities | null;
}

export interface PlanDay {
  date: string;
  is_overdue: boolean;
  totals: PlanQuantities;
  categories: PlanCategory[];
}

export interface ProductionPlan {
  from: string;
  to: string;
  today: string;
  page_names: "all" | "own";
  own_pages: PlanPage[];
  totals: PlanQuantities;
  days: PlanDay[];
}

export interface PlanFilters {
  from: string;
  to: string;
}

/** Polled every minute so new orders and confirmations appear without a reload. */
export function useProductionPlan(filters: PlanFilters) {
  return useQuery<ProductionPlan>({
    queryKey: ["production-plan", filters],
    queryFn: ({ signal }) =>
      api
        .get<{ data: ProductionPlan }>(
          "production-plan",
          {
            "filter[from]": filters.from || undefined,
            "filter[to]": filters.to || undefined,
          },
          signal,
        )
        .then((r) => r.data),
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  });
}
