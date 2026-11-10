import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";

export type OrderStatusValue =
  | "draft"
  | "pending_payment_verification"
  | "payment_rejected"
  | "urgent_approval_pending"
  | "urgent_approval_rejected"
  | "confirmed"
  | (string & {});

export type UrgentSlot = "second_admin" | "operation_admin";

export interface Ref {
  id: number;
  code?: string;
  name: string;
}

export interface OrderCustomer {
  id: number;
  customer_code: string;
  name: string;
  phone: string;
  whatsapp: string | null;
  other_phone: string | null;
  address: string | null;
}

export interface UrgentSlotState {
  slot: UrgentSlot;
  label: string;
  decision: "approved" | "rejected" | null;
  reason: string | null;
  decided_by: { id: number; name: string } | null;
  decided_at: string | null;
}

export interface Order {
  id: number;
  order_no: string;
  status: { value: OrderStatusValue; label: string };
  branch: Ref;
  page: Ref;
  customer: OrderCustomer;
  order_type: Ref | null;
  product_category: Ref | null;
  gross_quantity: number;
  due_date: string;
  currency: string;
  pricing: { unit_price_minor: number | null; subtotal_minor: number; discount_minor: number; tax_minor: number; delivery_fee_minor: number; total_minor: number };
  advance: { percent: number; required_minor: number; verified_minor: number; shortfall_minor: number; meets_requirement: boolean };
  payment_status: "unpaid" | "advance_pending" | "advance_paid" | "paid" | "refunded";
  production_eligible: boolean;
  urgent: { is_urgent: boolean; status: "not_required" | "pending" | "approved" | "rejected"; approvals_given: number | null; approvals_required: number };
  approval_status: string;
  submitted_at: string | null;
  created_at: string | null;
  version: number;
  urgent_approval?: {
    id: number;
    days_available: number;
    reference_date: string | null;
    due_date: string;
    requested_at: string | null;
    decision: "approved" | "rejected" | null;
    slots: UrgentSlotState[];
  };
  history?: { from: string | null; to: string; reason: string | null; actor: string | null; at: string | null }[];
  /** Present only from payment approval onwards. */
  job_details?: {
    material: { id: number; name: string } | null;
    material_details: string | null;
    design_instructions: string | null;
    custom_notes: string | null;
    overall_remarks: string | null;
    attachment: { name: string; mime_type: string; url: string } | null;
    submitted_at: string | null;
  };
  design_task?: { id: number; status: { value: string; label: string }; designer: { id: number; name: string } | null; correction_rounds: number } | null;
  bulk?: { job: { id: number; status: string } | null; checklist: { key: string; label: string; met: boolean }[] };
  abilities?: {
    update: boolean;
    submit: boolean;
    manage_job_details: boolean;
    submit_job_details: boolean;
    record_payment: boolean;
    view_payments: boolean;
    decide_urgent_second_admin: boolean;
    decide_urgent_operation_admin: boolean;
  };
}

export interface CatalogItem {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
}

export type OrderGroup = "intake" | "urgent" | "approved";

export interface OrderListParams {
  page?: number;
  per_page?: number;
  sort?: string;
  search?: string;
  group?: OrderGroup;
  status?: string;
  customer_id?: number;
}

export interface CustomerInput {
  name: string;
  phone: string;
  whatsapp: string;
  other_phone: string | null;
  address: string | null;
  confirm_distinct?: boolean;
}

export interface CreateOrderInput {
  branch_id: number;
  page_id: number;
  customer_id?: number;
  customer?: CustomerInput;
  order_type_id: number;
  due_date: string;
  product_type_id: number;
  gross_quantity: number;
  unit_price_minor: number;
  delivery_fee_minor?: number | null;
  discount_minor?: number;
  submit: boolean;
}

export type UpdateOrderInput = Partial<Pick<CreateOrderInput, "order_type_id" | "due_date" | "product_type_id" | "gross_quantity" | "unit_price_minor" | "delivery_fee_minor" | "discount_minor">> & { version: number };

export const PAYMENT_STATUS_LABELS: Record<Order["payment_status"], string> = {
  unpaid: "Unpaid",
  advance_pending: "Advance pending",
  advance_paid: "Advance paid",
  paid: "Paid",
  refunded: "Refunded",
};

export const orderKeys = {
  all: ["orders"] as const,
  list: (params: OrderListParams) => ["orders", "list", params] as const,
  detail: (id: number) => ["orders", "detail", id] as const,
  orderTypes: (active: boolean) => ["catalog", "order-types", active] as const,
  categories: (active: boolean) => ["catalog", "product-categories", active] as const,
};

export function useOrders(params: OrderListParams) {
  return useQuery({
    queryKey: orderKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Order>>(
        "orders",
        {
          page: params.page,
          per_page: params.per_page,
          sort: params.sort,
          "filter[search]": params.search,
          "filter[group]": params.group,
          "filter[status]": params.status,
          "filter[customer_id]": params.customer_id,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useOrder(id: number | null) {
  return useQuery({
    queryKey: orderKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: Order }>(`orders/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useOrderTypes(activeOnly = true) {
  return useQuery({
    queryKey: orderKeys.orderTypes(activeOnly),
    queryFn: ({ signal }) => api.get<{ data: { items: CatalogItem[] } }>("order-types", activeOnly ? { "filter[active]": 1 } : undefined, signal).then((r) => r.data.items),
    staleTime: 5 * 60_000,
  });
}

export function useProductCategories(activeOnly = true) {
  return useQuery({
    queryKey: orderKeys.categories(activeOnly),
    queryFn: ({ signal }) => api.get<{ data: { items: CatalogItem[] } }>("product-categories", activeOnly ? { "filter[active]": 1 } : undefined, signal).then((r) => r.data.items),
    staleTime: 5 * 60_000,
  });
}

function useOrderCache() {
  const queryClient = useQueryClient();
  return (order: Order) => {
    queryClient.setQueryData(orderKeys.detail(order.id), order);
    void queryClient.invalidateQueries({ queryKey: ["orders", "list"] });
    void queryClient.invalidateQueries({ queryKey: ["payments"] });
  };
}

export function useCreateOrder() {
  const cache = useOrderCache();
  return useMutation({
    mutationFn: (input: CreateOrderInput) => api.post<{ data: Order }>("orders", input).then((r) => r.data),
    onSuccess: cache,
  });
}

export function useOrderMutations(id: number) {
  const cache = useOrderCache();
  return {
    update: useMutation({ mutationFn: (input: UpdateOrderInput) => api.patch<{ data: Order }>(`orders/${id}`, input).then((r) => r.data), onSuccess: cache }),
    submit: useMutation({ mutationFn: (version: number) => api.post<{ data: Order }>(`orders/${id}/submit`, { version }).then((r) => r.data), onSuccess: cache }),
    decideUrgent: useMutation({
      mutationFn: (input: { slot: UrgentSlot; decision: "approved" | "rejected"; reason: string | null }) => api.post<{ data: Order }>(`orders/${id}/urgent-decisions`, input).then((r) => r.data),
      onSuccess: cache,
    }),
  };
}

export interface JobDetailsInput {
  version: number;
  material_id: string;
  material_details: string;
  design_instructions: string;
  custom_notes: string;
  overall_remarks: string;
  attachment: File | null;
}

export function useJobDetailsMutations(orderId: number) {
  const cache = useOrderCache();
  return {
    save: useMutation({
      mutationFn: (input: JobDetailsInput) => {
        const body = new FormData();
        body.set("version", String(input.version));
        body.set("material_id", input.material_id);
        for (const key of ["material_details", "design_instructions", "custom_notes", "overall_remarks"] as const) body.set(key, input[key]);
        if (input.attachment) body.set("attachment", input.attachment);
        return apiRequest<{ data: Order }>(`orders/${orderId}/job-details`, { method: "POST", body }).then((r) => r.data);
      },
      onSuccess: cache,
    }),
    submit: useMutation({ mutationFn: (version: number) => api.post<{ data: Order }>(`orders/${orderId}/job-details/submit`, { version }).then((r) => r.data), onSuccess: cache }),
  };
}

export function useSaveCatalogItem(kind: "order-types" | "product-categories" | "materials") {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...input }: { id?: number; code?: string; name?: string; is_active?: boolean }) =>
      (id === undefined ? api.post<{ data: CatalogItem }>(kind, input) : api.patch<{ data: CatalogItem }>(`${kind}/${id}`, input)).then((r) => r.data),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["catalog"] }),
  });
}

/** Badge tone for an order status. */
export function statusTone(status: OrderStatusValue): "neutral" | "success" | "danger" | "brand" {
  if (status === "confirmed") return "success";
  if (status === "payment_rejected" || status === "urgent_approval_rejected") return "danger";
  if (status === "draft") return "neutral";
  return "brand";
}
