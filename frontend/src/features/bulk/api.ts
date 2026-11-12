import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";

export type BulkStatus = "pending_assignment" | "assigned" | "in_progress" | "in_review" | "changes_requested" | "approved" | "completed" | "cancelled";
export type BulkMode = "internal" | "external";

export interface BulkFileRef {
  id: number;
  kind: "preview" | "file";
  name: string;
  mime_type: string;
  size_bytes: number;
  url: string;
}

export interface BulkVersion {
  id: number;
  version_no: number;
  is_final: boolean;
  is_current: boolean;
  is_approved: boolean;
  list_version_no: number;
  notes: string | null;
  external_url: string | null;
  transfer_url: string | null;
  submitted_by: string;
  submitted_at: string | null;
  files: BulkFileRef[];
  reviews: { decision: "approved" | "changes_requested"; reason: string | null; round_no: number | null; is_system: boolean; reviewed_by: string; reviewed_at: string | null }[];
}

export interface BulkMeasurement {
  key: string;
  label: string;
  unit: string;
  is_required: boolean;
  decimals: number;
  min_value: string | null;
  max_value: string | null;
  value: string | null;
  entered_by: string | null;
  entered_at: string | null;
  updated_by: string | null;
  updated_at: string | null;
}

export interface BulkChecklistItem {
  id: number;
  label: string;
  is_required: boolean;
  is_completed: boolean;
  completed_by: string | null;
  completed_at: string | null;
  notes: string | null;
}

export interface BulkJob {
  id: number;
  version: number;
  status: { value: BulkStatus; label: string };
  mode: BulkMode | null;
  job_no: string;
  order: { id: number; order_no: string; customer: string; page: string; due_date: string; quantity: number; is_urgent: boolean; status: { value: string; label: string } };
  branch: string;
  page: { code: string; name: string };
  product_category: string;
  material: string | null;
  design_preview_url: string | null;
  final_design_link: string | null;
  list: { version_no: number; total_quantity: number; status: string } | null;
  bulk_designer: { id: number; name: string } | null;
  supplier: { id: number; name: string } | null;
  assigned_at: string | null;
  created_at: string | null;
  completed_at: string | null;
  assignment?: {
    assigned_by: string | null;
    notes: string | null;
    history: { mode: BulkMode; bulk_designer: string | null; supplier: string | null; assigned_by: string; assigned_at: string | null; notes: string | null; reason: string | null; status: "current" | "ended"; ended_at: string | null }[];
  };
  design?: { approved_version_no: number | null; final_link: string | null; final_files: { name: string; url: string }[]; approved_files: { kind: string; name: string; url: string }[] };
  measurements?: BulkMeasurement[];
  measurement_history?: { field: string; old_value: string | null; new_value: string; is_correction: boolean; reason: string | null; changed_by: string; changed_at: string | null }[];
  checklist?: {
    id: number;
    stage: string;
    template: string;
    items: BulkChecklistItem[];
    client_confirmation: { confirmed_at: string; recorded_by: string | null; channel: string | null; comment: string | null; evidence_url: string | null } | null;
  } | null;
  versions?: BulkVersion[];
  review?: { correction_rounds: number; approved_by: string | null; approved_at: string | null; approved_list_version_no: number | null };
  evidence?: { kind: string; name: string; note: string | null; uploaded_by: string; uploaded_at: string | null; url: string }[];
  completion?: { completed_by: string | null; notes: string | null; missing: Record<string, string[]> | [] };
  abilities?: {
    assign: boolean;
    enter_measurements: boolean;
    update_checklist: boolean;
    confirm_checklist: boolean;
    submit_for_review: boolean;
    review: boolean;
    submit_final: boolean;
    manage_external: boolean;
    correct_measurements: boolean;
  };
}

export interface JobCardMeasurement {
  key: string;
  label: string;
  unit: string;
  is_required: boolean;
  value: string | null;
}

export interface JobCard {
  job_no: string;
  order_id: number;
  customer: string;
  branch: string;
  page: string;
  due_date: string;
  is_urgent: boolean;
  design_image_url: string | null;
  final_design_link: string | null;
  material: string | null;
  material_details: string | null;
  product: { category: string; order_type: string | null };
  quantity: number;
  bulk_meter: JobCardMeasurement | null;
  patch_meter: JobCardMeasurement | null;
  other_measurements: JobCardMeasurement[];
  list: { version_no: number; status: string; locked_at: string | null; is_approved_reference: boolean; is_current: boolean; total_quantity: number; by_size: { size: string; quantity: number }[]; groups: { label: string; quantity: number }[] } | null;
  bulk: { status: { value: BulkStatus; label: string }; mode: BulkMode | null; approved_version_no: number | null; completed_at: string | null };
}

export interface BulkListParams {
  page?: number;
  search?: string;
  status?: string;
  mode?: string;
  open?: boolean;
  sort?: string;
}

export const bulkKeys = {
  list: (p: object) => ["bulk-jobs", "list", p] as const,
  detail: (id: number) => ["bulk-jobs", "detail", id] as const,
  jobCard: (id: number) => ["bulk-jobs", "job-card", id] as const,
  garmentList: (id: number) => ["bulk-jobs", "garment-list", id] as const,
  assignees: ["bulk-jobs", "assignees"] as const,
  setup: ["bulk-setup"] as const,
};

export const BULK_STATUS_OPTIONS: { value: BulkStatus; label: string }[] = [
  { value: "pending_assignment", label: "Pending assignment" },
  { value: "assigned", label: "Assigned" },
  { value: "in_progress", label: "In progress" },
  { value: "in_review", label: "In review" },
  { value: "changes_requested", label: "Correction requested" },
  { value: "approved", label: "Approved" },
  { value: "completed", label: "Completed" },
];

export function bulkStatusTone(status: BulkStatus): "neutral" | "success" | "danger" | "brand" | "warning" {
  if (status === "completed") return "success";
  if (status === "changes_requested") return "danger";
  if (status === "pending_assignment") return "warning";
  if (status === "in_review" || status === "approved") return "brand";
  return "neutral";
}

export function useBulkJobs(params: BulkListParams) {
  return useQuery({
    queryKey: bulkKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<BulkJob>>(
        "bulk-jobs",
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

export function useBulkJob(id: number | null) {
  return useQuery({
    queryKey: bulkKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: BulkJob }>(`bulk-jobs/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useJobCard(id: number | null) {
  return useQuery({
    queryKey: bulkKeys.jobCard(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: JobCard }>(`bulk-jobs/${id}/job-card`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export interface BulkGarmentList {
  version: { id: number; version_no: number; status: string; locked_by: string | null; locked_at: string | null; total_quantity: number };
  schema: { fields: { key: string; label: string; input_type: string; options: { code: string; label: string }[] }[] };
  items: { line_no: number; size: string | null; quantity: number; player_name: string | null; number: string | null; notes: string | null; values: Record<string, string> }[];
}

export function useBulkGarmentList(id: number, enabled: boolean) {
  return useQuery({
    queryKey: bulkKeys.garmentList(id),
    queryFn: ({ signal }) => api.get<{ data: BulkGarmentList }>(`bulk-jobs/${id}/garment-list`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export interface BulkDesignerAssignee {
  id: number;
  name: string;
  active_jobs?: number;
  is_free?: boolean;
  has_access?: boolean;
}

export function useBulkAssignees(enabled: boolean, jobId?: number) {
  return useQuery({
    queryKey: [...bulkKeys.assignees, jobId],
    queryFn: ({ signal }) =>
      api
        .get<{ data: { designers: BulkDesignerAssignee[]; suppliers: { id: number; name: string; service_type: string }[] } }>(
          jobId ? `bulk-jobs/assignees?job_id=${jobId}` : "bulk-jobs/assignees",
          undefined,
          signal,
        )
        .then((r) => r.data),
    enabled,
  });
}

export interface AssignmentInput {
  mode: BulkMode;
  bulk_designer_id?: number | null;
  supplier_id?: number | null;
  notes?: string;
  reason?: string;
}

export interface BulkSubmissionInput {
  preview: File | null;
  files: File[];
  external_url?: string;
  transfer_url?: string;
  notes: string;
}

/** Multipart body for review / final submissions. `version` is the job's optimistic-lock version. */
export function submissionBody(version: number, input: BulkSubmissionInput): FormData {
  const body = new FormData();
  body.set("version", String(version));
  if (input.preview) body.set("preview", input.preview);
  input.files.forEach((f, i) => body.set(`files[${i}]`, f));
  if (input.external_url) body.set("external_url", input.external_url);
  if (input.transfer_url) body.set("transfer_url", input.transfer_url);
  if (input.notes) body.set("notes", input.notes);
  return body;
}

export function useBulkMutations(id: number) {
  const queryClient = useQueryClient();
  const done = (job: BulkJob) => {
    queryClient.setQueryData(bulkKeys.detail(id), job);
    void queryClient.invalidateQueries({ queryKey: ["bulk-jobs", "list"] });
    void queryClient.invalidateQueries({ queryKey: bulkKeys.jobCard(id) });
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
  };
  const current = () => queryClient.getQueryData<BulkJob>(bulkKeys.detail(id))?.version ?? 0;
  const post = (path: string, body: unknown) => api.post<{ data: BulkJob }>(`bulk-jobs/${id}/${path}`, body).then((r) => r.data);
  const multipart = (path: string, body: FormData) => apiRequest<{ data: BulkJob }>(`bulk-jobs/${id}/${path}`, { method: "POST", body }).then((r) => r.data);

  return {
    assign: useMutation({ mutationFn: (input: AssignmentInput) => post("assignment", { ...input, version: current() }), onSuccess: done }),
    measurements: useMutation({
      mutationFn: (values: Record<string, string>) => api.put<{ data: BulkJob }>(`bulk-jobs/${id}/measurements`, { values }).then((r) => r.data),
      onSuccess: done,
    }),
    correctMeasurements: useMutation({ mutationFn: (input: { values: Record<string, string>; reason: string }) => post("measurements/corrections", input), onSuccess: done }),
    checklistItem: useMutation({
      mutationFn: (input: { item: number; is_completed?: boolean; notes?: string | null }) =>
        api.patch<{ data: BulkJob }>(`bulk-jobs/${id}/checklist/items/${input.item}`, { is_completed: input.is_completed, notes: input.notes }).then((r) => r.data),
      onSuccess: done,
    }),
    confirmChecklist: useMutation({
      mutationFn: (input: { channel: string; comment: string; evidence: File | null }) => {
        const body = new FormData();
        body.set("channel", input.channel);
        if (input.comment) body.set("comment", input.comment);
        if (input.evidence) body.set("evidence", input.evidence);
        return multipart("checklist/client-confirmation", body);
      },
      onSuccess: done,
    }),
    submitForReview: useMutation({ mutationFn: (input: BulkSubmissionInput) => multipart("versions", submissionBody(current(), input)), onSuccess: done }),
    requestCorrection: useMutation({ mutationFn: (reason: string) => post("corrections", { reason, version: current() }), onSuccess: done }),
    approve: useMutation({ mutationFn: (comment: string) => post("approve", { comment, version: current() }), onSuccess: done }),
    submitFinal: useMutation({ mutationFn: (input: BulkSubmissionInput) => multipart("final", submissionBody(current(), input)), onSuccess: done }),
    addEvidence: useMutation({
      mutationFn: (input: { file: File; note: string }) => {
        const body = new FormData();
        body.set("file", input.file);
        if (input.note) body.set("note", input.note);
        return multipart("external/evidence", body);
      },
      onSuccess: done,
    }),
    completeExternal: useMutation({ mutationFn: (notes: string) => post("external/complete", { notes, version: current() }), onSuccess: done }),
  };
}

/* ---------- Setup ---------- */

export interface BulkSetup {
  product_types: { id: number; name: string }[];
  stages: { value: string; label: string }[];
  service_types: string[];
  measurement_fields: {
    id: number;
    key: string;
    label: string;
    unit: string;
    product_type: string | null;
    product_type_id: number | null;
    min_value: string;
    max_value: string;
    decimals: number;
    is_required: boolean;
    is_system: boolean;
    show_on_job_card: boolean;
    sort_order: number;
    is_active: boolean;
  }[];
  checklist_templates: { id: number; stage: string; name: string; version_no: number; is_active: boolean; product_type: string | null; product_type_id: number | null; items: { label: string; is_required: boolean }[] }[];
  suppliers: { id: number; name: string; phone: string | null; email: string | null; address: string | null; service_type: string; is_active: boolean }[];
}

export function useBulkSetup() {
  return useQuery({ queryKey: bulkKeys.setup, queryFn: ({ signal }) => api.get<{ data: BulkSetup }>("bulk-setup", undefined, signal).then((r) => r.data) });
}

export function useBulkSetupMutation<TInput>(request: (input: TInput) => Promise<unknown>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: bulkKeys.setup });
      void queryClient.invalidateQueries({ queryKey: bulkKeys.assignees });
    },
  });
}

/* ---------- Client-side hints (the server validates everything again) ---------- */

const TRANSFER_HOSTS = ["wetransfer.com", "www.wetransfer.com", "we.tl"];

export function isAllowedTransferLink(url: string): boolean {
  try {
    const u = new URL(url);
    return u.protocol === "https:" && TRANSFER_HOSTS.includes(u.hostname) && !u.username && !u.password && (u.port === "" || u.port === "443");
  } catch {
    return false;
  }
}

/** Mirrors the server rule: a positive decimal with at most `decimals` places within [min, max]. Returns an error or null. */
export function measurementError(m: Pick<BulkMeasurement, "label" | "decimals" | "min_value" | "max_value" | "unit">, raw: string): string | null {
  const value = raw.trim();
  if (value === "") return null;
  if (!/^\d{1,9}(\.\d+)?$/.test(value)) return `${m.label} must be a positive number.`;
  const decimals = value.includes(".") ? value.split(".")[1].length : 0;
  if (decimals > m.decimals) return `${m.label} can have at most ${m.decimals} decimal place(s).`;
  const n = Number(value);
  if ((m.min_value !== null && n < Number(m.min_value)) || (m.max_value !== null && n > Number(m.max_value))) {
    return `${m.label} must be between ${m.min_value} and ${m.max_value} ${m.unit}.`;
  }
  return null;
}

/** Flattens the server's "missing production data" map into readable lines. */
export function missingLines(missing: Record<string, string[]> | [] | undefined): string[] {
  if (!missing || Array.isArray(missing)) return [];
  return Object.values(missing).flat();
}
