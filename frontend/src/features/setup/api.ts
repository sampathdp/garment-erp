import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api-client";

/* ---------- Workflow settings (Super Admin) ---------- */

export interface WorkflowSettings {
  default_designer_id: number | null;
  default_bulk_designer_id: number | null;
  auto_assign_designer: boolean;
  auto_assign_bulk_designer: boolean;
  max_correction_rounds: number;
  designers: { id: number; name: string; active_tasks?: number; is_free?: boolean; has_access?: boolean }[];
  bulk_designers: { id: number; name: string; active_jobs?: number; is_free?: boolean; has_access?: boolean }[];
}

export function useWorkflowSettings() {
  return useQuery({ queryKey: ["settings", "workflow"], queryFn: ({ signal }) => api.get<{ data: WorkflowSettings }>("settings/workflow", undefined, signal).then((r) => r.data) });
}

export function useSaveWorkflowSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: {
      default_designer_id: number | null;
      default_bulk_designer_id: number | null;
      auto_assign_designer?: boolean;
      auto_assign_bulk_designer?: boolean;
      max_correction_rounds: number;
    }) =>
      api.put<{ data: WorkflowSettings }>("settings/workflow", input).then((r) => r.data),
    onSuccess: (data) => queryClient.setQueryData(["settings", "workflow"], data),
  });
}

/* ---------- Garment master data ---------- */

export interface SetupField {
  id: number;
  product_type_id: number;
  key: string;
  label: string;
  input_type: "text" | "number" | "select" | "boolean";
  garment_option_set_id: number | null;
  is_required: boolean;
  affects_price: boolean;
  warn_on_duplicate: boolean;
  max_length: number | null;
  sort_order: number;
  is_active: boolean;
}

export interface GarmentSetup {
  categories: { id: number; code: string; name: string; size_chart_id: number; is_active: boolean; fields: SetupField[] }[];
  option_sets: { id: number; code: string; name: string; options: { id: number; code: string; label: string; sort_order: number; is_active: boolean }[] }[];
  size_charts: { id: number; name: string; sizes: { id: number; code: string; label: string; sort_order: number }[] }[];
  field_types: string[];
}

export function useGarmentSetup() {
  return useQuery({ queryKey: ["garment-setup"], queryFn: ({ signal }) => api.get<{ data: GarmentSetup }>("garment-setup", undefined, signal).then((r) => r.data) });
}

/** Generic POST/PATCH against the garment-setup endpoints; refreshes the setup afterwards. */
export function useSetupMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ method, path, body }: { method: "post" | "patch"; path: string; body: object }) => (method === "post" ? api.post(path, body) : api.patch(path, body)),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["garment-setup"] }),
  });
}

/* ---------- Price lists ---------- */

export interface PriceRule {
  id: number;
  product_type: { id: number; name: string };
  material: { id: number; name: string } | null;
  branch: { id: number; code: string } | null;
  label: string | null;
  min_qty: number;
  max_qty: number | null;
  unit_price_minor: number;
  conditions: { field_key: string; option_code: string }[];
}

export interface PriceListRow {
  id: number;
  page: { id: number; code: string; name: string } | null;
  name: string;
  currency: string;
  valid_from: string;
  valid_to: string | null;
  is_active: boolean;
  in_use: boolean;
  rules_count: number | null;
  rules: PriceRule[] | null;
  abilities: { update: boolean };
}

export function usePriceLists() {
  return useQuery({ queryKey: ["price-lists"], queryFn: ({ signal }) => api.get<{ data: { items: PriceListRow[] } }>("price-lists", undefined, signal).then((r) => r.data.items) });
}

export function usePriceList(id: number | null) {
  return useQuery({ queryKey: ["price-lists", id], queryFn: ({ signal }) => api.get<{ data: PriceListRow }>(`price-lists/${id}`, undefined, signal).then((r) => r.data), enabled: id !== null });
}

export function usePriceListMutations(id: number | null) {
  const queryClient = useQueryClient();
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["price-lists"] });
  return {
    create: useMutation({
      mutationFn: (input: { page_id: number; name: string; valid_from: string; valid_to: string | null; copy_from_id: number | null }) => api.post<{ data: PriceListRow }>("price-lists", input).then((r) => r.data),
      onSuccess: refresh,
    }),
    update: useMutation({ mutationFn: (input: { name?: string; valid_to?: string | null; is_active?: boolean }) => api.patch(`price-lists/${id}`, input), onSuccess: refresh }),
    saveRule: useMutation({
      mutationFn: ({ ruleId, ...input }: { ruleId?: number; product_type_id: number; material_id: number | null; branch_id: number | null; label: string | null; min_qty: number; max_qty: number | null; unit_price_minor: number; conditions: { field_key: string; option_code: string }[] }) =>
        ruleId === undefined ? api.post(`price-lists/${id}/rules`, input) : api.patch(`price-lists/${id}/rules/${ruleId}`, input),
      onSuccess: refresh,
    }),
    deleteRule: useMutation({ mutationFn: (ruleId: number) => api.delete(`price-lists/${id}/rules/${ruleId}`), onSuccess: refresh }),
  };
}

/* ---------- Invoices ---------- */

export interface Invoice {
  id: number;
  invoice_no: string;
  type: string;
  status: "draft" | "issued" | "void";
  currency: string;
  subtotal_minor: number;
  discount_minor: number;
  tax_percent: number;
  tax_minor: number;
  delivery_fee_minor: number;
  total_minor: number;
  pricing_date: string | null;
  issued_at: string | null;
  void_reason: string | null;
  payments: { advance_percent: number; advance_required_minor: number; verified_paid_minor: number; advance_shortfall_minor: number; balance_minor: number } | null;
  order: { id: number; order_no?: string; customer?: string };
  page: { code: string; name: string; header: string } | null;
  issued_by: string | null;
  lines: { description: string; quantity: number; unit_price_minor: number; line_total_minor: number }[] | null;
}

export function useOrderInvoices(orderId: number, enabled: boolean) {
  return useQuery({
    queryKey: ["invoices", "order", orderId],
    queryFn: ({ signal }) => api.get<{ data: { items: Invoice[] } }>(`orders/${orderId}/invoices`, undefined, signal).then((r) => r.data.items),
    enabled,
  });
}

export function useInvoice(id: number | null) {
  return useQuery({ queryKey: ["invoices", id], queryFn: ({ signal }) => api.get<{ data: Invoice }>(`invoices/${id}`, undefined, signal).then((r) => r.data), enabled: id !== null });
}


export function useMaterials(activeOnly = true) {
  return useQuery({
    queryKey: ["catalog", "materials", activeOnly],
    queryFn: ({ signal }) => api.get<{ data: { items: { id: number; code: string; name: string; is_active: boolean }[] } }>("materials", activeOnly ? { "filter[active]": 1 } : undefined, signal).then((r) => r.data.items),
    staleTime: 5 * 60_000,
  });
}
