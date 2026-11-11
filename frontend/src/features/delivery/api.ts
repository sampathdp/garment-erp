import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";

export type DeliveryStatus = "ready" | "dispatched" | "delivered";
export type DeliveryMethod = "pickup" | "courier" | "own_vehicle";
export type DeliveryDocumentType = "delivery_sheet" | "waybill" | "delivery_photo" | "customer_photo";

export interface Money {
  amount_minor: number;
  formatted: string;
}

export interface Delivery {
  id: number;
  version: number;
  status: { value: DeliveryStatus; label: string };
  job_no: string;
  order: { id: number; due_date: string; is_urgent: boolean; completed_at: string | null };
  customer: { name: string; phone: string | null; address: string | null };
  branch: string;
  page: { code: string; name: string };
  quantity: number;
  product: { category: string | null; material: string | null };
  production_status: { value: string; label: string; is_completed: boolean };
  method: { value: DeliveryMethod; label: string } | null;
  balance: Money;
  cod: { amount: Money; collected: Money | null; status: string };
  documents_missing: { value: DeliveryDocumentType; label: string }[][];
  dispatched_at: string | null;
  delivered_at: string | null;
  dispatch?: { by: string | null; courier_name: string | null; tracking_no: string | null; scheduled_date: string | null };
  delivery?: { by: string | null; received_by: string | null; notes: string | null };
  documents?: { id: number; type: { value: DeliveryDocumentType; label: string }; name: string; mime_type: string; notes: string | null; uploaded_by: string; uploaded_at: string | null; url: string }[];
  completion?: { completed_by: string | null; completed_at: string | null; notes: string | null; blockers: Record<string, string[]> };
  abilities?: { dispatch: boolean; deliver: boolean; upload_document: boolean; complete_job: boolean };
}

export const DELIVERY_METHODS: { value: DeliveryMethod; label: string }[] = [
  { value: "courier", label: "Courier" },
  { value: "own_vehicle", label: "Own vehicle" },
  { value: "pickup", label: "Customer pickup" },
];

export const DOCUMENT_TYPES: { value: DeliveryDocumentType; label: string }[] = [
  { value: "delivery_sheet", label: "Delivery sheet" },
  { value: "waybill", label: "Waybill" },
  { value: "delivery_photo", label: "Delivery photo" },
  { value: "customer_photo", label: "Customer photo" },
];

export const COMPLETION_GROUPS: Record<string, string> = {
  production: "Production",
  checks: "Cutting checks",
  issues: "Blocking issues",
  delivery: "Delivery",
  documents: "Delivery documents",
  balance: "Balance",
};

/** Which documents a method requires before completion (mirrors the server rule; any one of a set). */
export function requiredDocuments(method: DeliveryMethod | null): DeliveryDocumentType[] {
  if (method === "courier") return ["waybill"];
  if (method === "own_vehicle") return ["delivery_sheet"];
  if (method === "pickup") return ["delivery_photo", "customer_photo"];
  return [];
}

export function deliveryStatusTone(status: DeliveryStatus, completed: boolean): "success" | "brand" | "warning" | "neutral" {
  if (completed) return "success";
  if (status === "delivered") return "brand";
  if (status === "dispatched") return "neutral";
  return "warning";
}

export const deliveryKeys = {
  list: (p: object) => ["deliveries", "list", p] as const,
  detail: (id: number) => ["deliveries", "detail", id] as const,
};

export function useDeliveries(params: { page: number; search: string; status: string; open: boolean; missing: boolean; sort: string }) {
  return useQuery({
    queryKey: deliveryKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Delivery>>(
        "deliveries",
        {
          page: params.page,
          sort: params.sort,
          "filter[search]": params.search || undefined,
          "filter[status]": params.status || undefined,
          "filter[open]": params.open ? 1 : undefined,
          "filter[missing_documents]": params.missing ? 1 : undefined,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useDelivery(id: number | null) {
  return useQuery({
    queryKey: deliveryKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: Delivery }>(`deliveries/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useDeliveryMutations(id: number) {
  const queryClient = useQueryClient();
  const done = (d: Delivery) => {
    queryClient.setQueryData(deliveryKeys.detail(id), d);
    void queryClient.invalidateQueries({ queryKey: ["deliveries", "list"] });
    void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
  };
  const current = () => queryClient.getQueryData<Delivery>(deliveryKeys.detail(id))?.version ?? 0;
  const post = (path: string, body: unknown) => api.post<{ data: Delivery }>(`deliveries/${id}/${path}`, body).then((r) => r.data);

  return {
    dispatch: useMutation({
      mutationFn: (input: { method: DeliveryMethod; courier_name: string; tracking_no: string; scheduled_date: string; notes: string }) =>
        post("dispatch", { ...input, courier_name: input.courier_name || null, tracking_no: input.tracking_no || null, scheduled_date: input.scheduled_date || null, notes: input.notes || null, version: current() }),
      onSuccess: done,
    }),
    deliver: useMutation({
      mutationFn: (input: { method: DeliveryMethod | null; received_by: string; cod_collected_minor: number; notes: string }) =>
        post("deliver", { ...input, notes: input.notes || null, version: current() }),
      onSuccess: done,
    }),
    upload: useMutation({
      mutationFn: (input: { type: DeliveryDocumentType; file: File; notes: string }) => {
        const body = new FormData();
        body.set("type", input.type);
        body.set("file", input.file);
        if (input.notes.trim()) body.set("notes", input.notes.trim());
        return apiRequest<{ data: Delivery }>(`deliveries/${id}/documents`, { method: "POST", body }).then((r) => r.data);
      },
      onSuccess: done,
    }),
    complete: useMutation({ mutationFn: (notes: string) => post("complete", { notes: notes || null, version: current() }), onSuccess: done }),
  };
}
