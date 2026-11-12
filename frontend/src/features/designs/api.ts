import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";

export type DesignStatus = "pending" | "in_progress" | "in_review" | "changes_requested" | "approved" | "completed";

export const APPROVAL_CHANNELS = [
  { value: "whatsapp", label: "WhatsApp" },
  { value: "messenger", label: "Messenger" },
  { value: "phone", label: "Phone" },
  { value: "in_person", label: "In person" },
  { value: "email", label: "Email" },
];

export interface DesignFileRef {
  id: number;
  kind: "source" | "preview" | "mockup";
  name: string;
  mime_type: string;
  size_bytes: number;
  url: string;
}

export interface DesignVersion {
  id: number;
  version_no: number;
  previous_version_id: number | null;
  is_final: boolean;
  is_approved: boolean;
  notes: string | null;
  external_url: string | null;
  uploaded_by: string;
  uploaded_at: string | null;
  files: DesignFileRef[];
  reviews: { decision: "approved" | "changes_requested"; channel: string; comment: string | null; recorded_by: string; at: string | null }[];
}

export interface DesignTask {
  id: number;
  status: { value: DesignStatus; label: string };
  designer: { id: number; name: string } | null;
  correction_rounds: number;
  order: {
    id: number;
    order_no: string;
    branch: { code: string; name: string };
    page: { code: string; name: string };
    customer: string;
    product_category: string;
    quantity: number;
    due_date: string;
    is_urgent: boolean;
    status: { value: string; label: string };
  };
  created_at: string | null;
  completed_at: string | null;
  job_details?: {
    material: string | null;
    material_details: string | null;
    design_instructions: string | null;
    custom_notes: string | null;
    overall_remarks: string | null;
    attachment: { url: string } | null;
  };
  max_correction_rounds?: number;
  versions?: DesignVersion[];
  corrections?: { round_no: number; reason: string; requested_by: string | null; requested_at: string | null; resolved_in_version_id: number | null; override_reason: string | null }[];
  abilities?: { assign: boolean; upload_version: boolean; request_correction: boolean; approve: boolean; submit_final: boolean };
}

export const designKeys = {
  list: (p: object) => ["designs", "list", p] as const,
  detail: (id: number) => ["designs", "detail", id] as const,
  designers: ["designs", "designers"] as const,
};

export function useDesignTasks(params: { page?: number; search?: string; status?: string; open?: boolean; sort?: string }) {
  return useQuery({
    queryKey: designKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<DesignTask>>("design-tasks", { page: params.page, sort: params.sort, "filter[search]": params.search, "filter[status]": params.status, "filter[open]": params.open ? 1 : undefined }, signal),
    placeholderData: keepPreviousData,
  });
}

export function useDesignTask(id: number | null) {
  return useQuery({
    queryKey: designKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: DesignTask }>(`design-tasks/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export interface DesignerItem {
  id: number;
  name: string;
  active_tasks?: number;
  is_free?: boolean;
  has_access?: boolean;
}

export function useDesigners(enabled: boolean, taskId?: number) {
  return useQuery({
    queryKey: [...designKeys.designers, taskId],
    queryFn: ({ signal }) =>
      api
        .get<{ data: { items: DesignerItem[] } }>(
          taskId ? `design-tasks/designers?task_id=${taskId}` : "design-tasks/designers",
          undefined,
          signal,
        )
        .then((r) => r.data.items),
    enabled,
  });
}

export interface SubmissionInput {
  preview?: File | null;
  files: File[];
  external_url: string;
  notes: string;
}

function submissionBody(input: SubmissionInput): FormData {
  const body = new FormData();
  if (input.preview) body.set("preview", input.preview);
  input.files.forEach((f, i) => body.set(`files[${i}]`, f));
  if (input.external_url) body.set("external_url", input.external_url);
  if (input.notes) body.set("notes", input.notes);
  return body;
}

export function useDesignMutations(id: number) {
  const queryClient = useQueryClient();
  const done = (task: DesignTask) => {
    queryClient.setQueryData(designKeys.detail(id), task);
    void queryClient.invalidateQueries({ queryKey: ["designs", "list"] });
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
  };
  return {
    assign: useMutation({ mutationFn: (designerId: number) => api.post<{ data: DesignTask }>(`design-tasks/${id}/assign`, { designer_id: designerId }).then((r) => r.data), onSuccess: done }),
    submitVersion: useMutation({
      mutationFn: (input: SubmissionInput) => apiRequest<{ data: DesignTask }>(`design-tasks/${id}/versions`, { method: "POST", body: submissionBody(input) }).then((r) => r.data),
      onSuccess: done,
    }),
    submitFinal: useMutation({
      mutationFn: (input: SubmissionInput) => apiRequest<{ data: DesignTask }>(`design-tasks/${id}/final`, { method: "POST", body: submissionBody({ ...input, preview: null }) }).then((r) => r.data),
      onSuccess: done,
    }),
    requestCorrection: useMutation({
      mutationFn: (input: { reason: string; channel: string; override_reason?: string }) => api.post<{ data: DesignTask }>(`design-tasks/${id}/corrections`, input).then((r) => r.data),
      onSuccess: done,
    }),
    approve: useMutation({
      mutationFn: (input: { channel: string; comment: string }) => api.post<{ data: DesignTask }>(`design-tasks/${id}/approve`, input).then((r) => r.data),
      onSuccess: done,
    }),
  };
}

/** Client-side hint only; the server enforces the allow-list and never fetches the link. */
export function isAllowedDesignLink(url: string): boolean {
  try {
    const u = new URL(url);
    return u.protocol === "https:" && ["drive.google.com", "docs.google.com"].includes(u.hostname) && !u.username && !u.password && (u.port === "" || u.port === "443");
  } catch {
    return false;
  }
}
