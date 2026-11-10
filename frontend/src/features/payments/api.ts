import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";

export type PaymentStatusValue = "pending_verification" | "verified" | "rejected" | "reversed";

export interface Payment {
  id: number;
  order: {
    id: number;
    order_no: string;
    status: { value: string; label: string };
    customer: { id: number; name: string } | null;
    page: { id: number; code: string; name: string } | null;
    total_minor: number;
    advance_required_minor: number;
    verified_minor: number;
    shortfall_minor: number;
    meets_requirement: boolean;
  } | null;
  amount_minor: number;
  currency: string;
  method: string;
  reference_no: string | null;
  payment_date: string;
  status: PaymentStatusValue;
  recorded_by: { id: number; name: string } | null;
  recorded_at: string | null;
  verified_by: { id: number; name: string } | null;
  verified_at: string | null;
  verification_notes: string | null;
  rejection_reason: string | null;
  evidence?: { name: string; mime_type: string; size_bytes: number; url: string } | null;
  history?: { action: "submitted" | "verified" | "rejected"; notes: string | null; actor: string | null; at: string | null }[];
  abilities?: { verify: boolean; reject: boolean };
}

export const PAYMENT_METHODS = [
  { value: "bank_transfer", label: "Bank transfer" },
  { value: "cash", label: "Cash" },
  { value: "card", label: "Card" },
  { value: "online", label: "Online payment" },
  { value: "cheque", label: "Cheque" },
];

/** Methods whose slip/screenshot and reference are required (mirrors the API rule). */
export const EVIDENCE_REQUIRED_METHODS = new Set(["bank_transfer", "online", "cheque"]);

export const PAYMENT_STATUS_LABELS: Record<PaymentStatusValue, string> = {
  pending_verification: "Pending verification",
  verified: "Confirmed",
  rejected: "Rejected",
  reversed: "Reversed",
};

export const paymentKeys = {
  list: (params: object) => ["payments", "list", params] as const,
  detail: (id: number) => ["payments", "detail", id] as const,
  forOrder: (orderId: number) => ["payments", "order", orderId] as const,
};

export function usePayments(params: { page?: number; status?: PaymentStatusValue | ""; search?: string; sort?: string }) {
  return useQuery({
    queryKey: paymentKeys.list(params),
    queryFn: ({ signal }) => api.get<Paginated<Payment>>("payments", { page: params.page, sort: params.sort, "filter[status]": params.status, "filter[search]": params.search }, signal),
    placeholderData: keepPreviousData,
  });
}

export function usePayment(id: number | null) {
  return useQuery({
    queryKey: paymentKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: Payment }>(`payments/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useOrderPayments(orderId: number, enabled: boolean) {
  return useQuery({
    queryKey: paymentKeys.forOrder(orderId),
    queryFn: ({ signal }) => api.get<{ data: { items: Payment[] } }>(`orders/${orderId}/payments`, undefined, signal).then((r) => r.data.items),
    enabled,
  });
}

export interface RecordPaymentInput {
  amount_minor: number;
  method: string;
  reference_no: string;
  payment_date: string;
  notes: string;
  evidence: File | null;
}

/** multipart upload with an Idempotency-Key, so a double click never records two payments. */
export function useRecordPayment(orderId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ input, idempotencyKey }: { input: RecordPaymentInput; idempotencyKey: string }) => {
      const body = new FormData();
      body.set("amount_minor", String(input.amount_minor));
      body.set("method", input.method);
      body.set("payment_date", input.payment_date);
      if (input.reference_no) body.set("reference_no", input.reference_no);
      if (input.notes) body.set("notes", input.notes);
      if (input.evidence) body.set("evidence", input.evidence);
      return apiRequest<{ data: Payment }>(`orders/${orderId}/payments`, { method: "POST", body, headers: { "Idempotency-Key": idempotencyKey } }).then((r) => r.data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["payments"] });
      void queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
  });
}

export function useDecidePayment(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ verify, text }: { verify: boolean; text: string }) =>
      (verify ? api.post<{ data: Payment }>(`payments/${id}/verify`, { notes: text || null }) : api.post<{ data: Payment }>(`payments/${id}/reject`, { reason: text })).then((r) => r.data),
    onSuccess: (payment) => {
      queryClient.setQueryData(paymentKeys.detail(id), payment);
      void queryClient.invalidateQueries({ queryKey: ["payments", "list"] });
      void queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
  });
}
