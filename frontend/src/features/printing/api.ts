import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiBlob, apiRequest, type Paginated } from "@/lib/api-client";
import type { BulkGarmentList, BulkMode, JobCard } from "@/features/bulk/api";

export type PrintStatus =
  | "ready_for_printing"
  | "sent_for_printing"
  | "sample_pending_approval"
  | "sample_rejected"
  | "sample_approved"
  | "physical_printing"
  | "printing_completed"
  | "invalidated";

export type SampleReviewStatus = "pending" | "approved" | "rejected";
export type PrintDocumentType = "job-card" | "name-list";

export interface PrintMeasurement {
  key: string;
  label: string;
  unit: string;
  value: string | null;
}

export interface PrintSampleVersion {
  id: number;
  version: number;
  is_current: boolean;
  is_approved: boolean;
  notes: string | null;
  uploaded_by: string | null;
  uploaded_at: string | null;
  review_status: { value: SampleReviewStatus; label: string };
  reviewed_by: string | null;
  reviewed_at: string | null;
  rejection_reason: string | null;
  comment: string | null;
  channel: string | null;
  image_url: string | null;
  documents: { name: string; mime_type: string; size_bytes: number; url: string }[];
}

export interface PrintDocumentAccess {
  label: string;
  viewable: boolean;
  printable: boolean;
  print_locked_reason: string | null;
}

export interface PrintJob {
  id: number;
  version: number;
  status: { value: PrintStatus; label: string };
  job_no: string;
  order: { id: number; customer: string; due_date: string; is_urgent: boolean; quantity: number; status: { value: string; label: string } };
  branch: string;
  page: { code: string; name: string };
  product_category: string;
  material: string | null;
  quantity: number;
  design_preview_url: string | null;
  design_version_no: number;
  mode: BulkMode;
  bulk: {
    id: number;
    producer: string | null;
    completed_at: string | null;
    final_version_no: number | null;
    transfer_url: string | null;
    files: { id: number; kind: "preview" | "file"; name: string; mime_type: string; size_bytes: number; url: string }[];
  };
  bulk_meter: PrintMeasurement | null;
  patch_meter: PrintMeasurement | null;
  collar_meter: PrintMeasurement | null;
  other_measurements: PrintMeasurement[];
  list: { version_no: number; total_quantity: number; status: string };
  supplier: { id: number; name: string } | null;
  recipient_name: string | null;
  sent_at: string | null;
  latest_sample: { sample_no: number; review_status: SampleReviewStatus } | null;
  created_at: string | null;
  sending?: { sent_by: string | null; sent_at: string | null; printing_company: string | null; recipient_name: string | null; notes: string | null };
  samples?: PrintSampleVersion[];
  printing?: { started_by: string | null; started_at: string | null; completed_by: string | null; completed_at: string | null; printed_quantity: number | null; notes: string | null };
  gate_passes?: { id: number; gate_pass_no: string; gate_pass_date: string; notes: string | null; document: { name: string; url: string } | null; uploaded_by: string; uploaded_at: string | null }[];
  history?: { from: string | null; to: string; note: string | null; by: string; at: string | null }[];
  documents?: { job_card: PrintDocumentAccess; name_list: PrintDocumentAccess };
  abilities?: {
    send: boolean;
    upload_sample: boolean;
    review_sample: boolean;
    start_printing: boolean;
    complete_printing: boolean;
    print_documents: boolean;
    record_gate_pass: boolean;
  };
}

export type PrintJobCard = JobCard & { printing: { id: number; status: { value: PrintStatus; label: string } } };

export interface PrintListParams {
  page?: number;
  search?: string;
  status?: string;
  mode?: string;
  open?: boolean;
  sort?: string;
}

export const PRINT_STATUS_OPTIONS: { value: PrintStatus; label: string }[] = [
  { value: "ready_for_printing", label: "Ready for Printing" },
  { value: "sent_for_printing", label: "Sent for Printing" },
  { value: "sample_pending_approval", label: "Sample Pending Approval" },
  { value: "sample_rejected", label: "Sample Rejected" },
  { value: "sample_approved", label: "Sample Approved" },
  { value: "physical_printing", label: "Physical Printing In Progress" },
  { value: "printing_completed", label: "Printing Completed" },
];

export function printStatusTone(status: PrintStatus): "neutral" | "success" | "danger" | "brand" | "warning" {
  if (status === "printing_completed" || status === "sample_approved") return "success";
  if (status === "sample_rejected" || status === "invalidated") return "danger";
  if (status === "ready_for_printing" || status === "sample_pending_approval") return "warning";
  return "brand";
}

export function sampleTone(status: SampleReviewStatus): "success" | "danger" | "warning" {
  return status === "approved" ? "success" : status === "rejected" ? "danger" : "warning";
}

export const printKeys = {
  list: (p: object) => ["print-jobs", "list", p] as const,
  detail: (id: number) => ["print-jobs", "detail", id] as const,
  jobCard: (id: number) => ["print-jobs", "job-card", id] as const,
  nameList: (id: number) => ["print-jobs", "name-list", id] as const,
  recipients: ["print-jobs", "recipients"] as const,
};

export function usePrintJobs(params: PrintListParams) {
  return useQuery({
    queryKey: printKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<PrintJob>>(
        "print-jobs",
        {
          page: params.page,
          sort: params.sort,
          "filter[search]": params.search || undefined,
          "filter[status]": params.status || undefined,
          "filter[mode]": params.mode || undefined,
          "filter[open]": params.open ? 1 : undefined,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function usePrintJob(id: number | null) {
  return useQuery({
    queryKey: printKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: PrintJob }>(`print-jobs/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function usePrintJobCard(id: number, enabled: boolean) {
  return useQuery({
    queryKey: printKeys.jobCard(id),
    queryFn: ({ signal }) => api.get<{ data: PrintJobCard }>(`print-jobs/${id}/job-card`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export function usePrintNameList(id: number, enabled: boolean) {
  return useQuery({
    queryKey: printKeys.nameList(id),
    queryFn: ({ signal }) => api.get<{ data: BulkGarmentList }>(`print-jobs/${id}/name-list`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export function usePrintRecipients(enabled: boolean) {
  return useQuery({
    queryKey: printKeys.recipients,
    queryFn: ({ signal }) => api.get<{ data: { suppliers: { id: number; name: string; service_type: string }[] } }>("print-jobs/recipients", undefined, signal).then((r) => r.data),
    enabled,
  });
}

export interface SendInput {
  supplier_id: number | null;
  recipient_name: string;
  notes: string;
}

export interface SampleInput {
  image: File;
  documents: File[];
  notes: string;
}

export interface GatePassInput {
  gate_pass_no: string;
  gate_pass_date: string;
  document: File | null;
  notes: string;
}

/** Multipart body for a sample upload. `version` is the job's optimistic-lock version. */
export function sampleBody(version: number, input: SampleInput): FormData {
  const body = new FormData();
  body.set("version", String(version));
  body.set("image", input.image);
  input.documents.forEach((f, i) => body.set(`documents[${i}]`, f));
  if (input.notes.trim()) body.set("notes", input.notes.trim());
  return body;
}

export function gatePassBody(input: GatePassInput): FormData {
  const body = new FormData();
  body.set("gate_pass_no", input.gate_pass_no.trim());
  body.set("gate_pass_date", input.gate_pass_date);
  if (input.document) body.set("document", input.document);
  if (input.notes.trim()) body.set("notes", input.notes.trim());
  return body;
}

export function usePrintMutations(id: number) {
  const queryClient = useQueryClient();
  const done = (job: PrintJob) => {
    queryClient.setQueryData(printKeys.detail(id), job);
    void queryClient.invalidateQueries({ queryKey: ["print-jobs", "list"] });
    void queryClient.invalidateQueries({ queryKey: printKeys.jobCard(id) });
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
  };
  const current = () => queryClient.getQueryData<PrintJob>(printKeys.detail(id))?.version ?? 0;
  const post = (path: string, body: unknown) => api.post<{ data: PrintJob }>(`print-jobs/${id}/${path}`, body).then((r) => r.data);
  const multipart = (path: string, body: FormData) => apiRequest<{ data: PrintJob }>(`print-jobs/${id}/${path}`, { method: "POST", body }).then((r) => r.data);

  return {
    send: useMutation({ mutationFn: (input: SendInput) => post("send", { ...input, version: current() }), onSuccess: done }),
    uploadSample: useMutation({ mutationFn: (input: SampleInput) => multipart("samples", sampleBody(current(), input)), onSuccess: done }),
    approve: useMutation({ mutationFn: (input: { comment: string; channel: string }) => post("samples/approve", { comment: input.comment || null, channel: input.channel || null, version: current() }), onSuccess: done }),
    reject: useMutation({ mutationFn: (input: { reason: string; channel: string }) => post("samples/reject", { reason: input.reason, channel: input.channel || null, version: current() }), onSuccess: done }),
    start: useMutation({ mutationFn: (notes: string) => post("physical-printing/start", { notes: notes || null, version: current() }), onSuccess: done }),
    complete: useMutation({ mutationFn: (input: { printed_quantity: number; notes: string }) => post("physical-printing/complete", { ...input, notes: input.notes || null, version: current() }), onSuccess: done }),
    gatePass: useMutation({ mutationFn: (input: GatePassInput) => multipart("gate-passes", gatePassBody(input)), onSuccess: done }),
  };
}

/**
 * Prints a server-generated document. The server refuses (409) until printing is allowed, so a
 * hidden or re-enabled button never bypasses the rule. The HTML is shown in a sandboxed iframe
 * (no scripts) and printed from there.
 */
export async function printServerDocument(jobId: number, document: PrintDocumentType): Promise<void> {
  const blob = await apiBlob(`print-jobs/${jobId}/documents/${document}/print`);
  const url = URL.createObjectURL(blob);
  const frame = window.document.createElement("iframe");
  frame.setAttribute("sandbox", "allow-same-origin allow-modals");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
  frame.src = url;
  frame.onload = () => {
    try {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
    } finally {
      window.setTimeout(() => {
        URL.revokeObjectURL(url);
        frame.remove();
      }, 60_000);
    }
  };
  window.document.body.appendChild(frame);
}

/* ---------- Client-side hints (the server validates everything again) ---------- */

const SAMPLE_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];
const MAX_SAMPLE_BYTES = 10 * 1024 * 1024;

export function sampleImageError(file: File | null): string | null {
  if (!file) return "Choose the sample image.";
  if (!SAMPLE_IMAGE_TYPES.includes(file.type)) return "The sample image must be a JPG, PNG or WebP image.";
  if (file.size === 0 || file.size > MAX_SAMPLE_BYTES) return "The sample image must not be empty or larger than 10 MB.";
  return null;
}

export function gatePassNumberError(value: string): string | null {
  const v = value.trim();
  if (!v) return "Enter the gate pass number.";
  if (v.length > 50 || !/^[A-Za-z0-9][A-Za-z0-9/\-_. ]*$/.test(v)) return "Use letters, numbers, spaces and / - _ . only (max 50).";
  return null;
}
