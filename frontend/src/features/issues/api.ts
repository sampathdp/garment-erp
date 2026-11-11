import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";

export type IssueStatus = "open" | "under_investigation" | "correction_required" | "resolved" | "closed";
export type IssueCategory =
  | "damaged_pieces"
  | "incorrect_cut"
  | "missing_parts"
  | "missing_items"
  | "printing_defect"
  | "bulk_error"
  | "correction_request"
  | "material"
  | "machine"
  | "quality"
  | "other";

export interface ProductionIssue {
  id: number;
  version: number;
  job_no: string;
  order: { id: number; customer: string; status: { value: string; label: string } };
  branch: string;
  page: { code: string; name: string };
  cutting_job_id: number | null;
  stage: string;
  category: { value: IssueCategory; label: string; bulk_related: boolean };
  status: { value: IssueStatus; label: string; unresolved: boolean };
  description: string;
  quantity_affected: number | null;
  part: { value: string; label: string } | null;
  size: string | null;
  is_blocking: boolean;
  has_image: boolean;
  reported_by: string;
  reported_at: string | null;
  assigned_to: { id: number; name: string } | null;
  assigned_at: string | null;
  resolution: string | null;
  resolved_at: string | null;
  image_url?: string;
  resolved_by?: string | null;
  closed_by?: string | null;
  closed_at?: string | null;
  next_statuses?: { value: IssueStatus; label: string }[];
  history?: { from: string | null; to: string; note: string | null; by: string; at: string | null }[];
  abilities?: { manage: boolean; transition: boolean };
}

export const ISSUE_CATEGORIES: { value: IssueCategory; label: string; needsQuantity: boolean }[] = [
  { value: "damaged_pieces", label: "Damaged pieces", needsQuantity: true },
  { value: "incorrect_cut", label: "Incorrect cut", needsQuantity: true },
  { value: "missing_parts", label: "Missing sleeves / parts", needsQuantity: true },
  { value: "missing_items", label: "Missing items", needsQuantity: true },
  { value: "printing_defect", label: "Printing defect", needsQuantity: true },
  { value: "bulk_error", label: "Bulk error", needsQuantity: false },
  { value: "correction_request", label: "Correction request", needsQuantity: false },
  { value: "material", label: "Material", needsQuantity: false },
  { value: "machine", label: "Machine", needsQuantity: false },
  { value: "quality", label: "Quality", needsQuantity: false },
  { value: "other", label: "Other", needsQuantity: false },
];

export const GARMENT_PARTS = ["sleeve", "collar", "front", "back", "cuff", "pocket", "waistband", "other"].map((v) => ({ value: v, label: v[0].toUpperCase() + v.slice(1) }));

export const ISSUE_STATUS_OPTIONS: { value: IssueStatus; label: string }[] = [
  { value: "open", label: "Open" },
  { value: "under_investigation", label: "Under Investigation" },
  { value: "correction_required", label: "Correction Required" },
  { value: "resolved", label: "Resolved" },
  { value: "closed", label: "Closed" },
];

export function issueStatusTone(status: IssueStatus): "danger" | "warning" | "brand" | "success" | "neutral" {
  if (status === "open") return "danger";
  if (status === "correction_required") return "warning";
  if (status === "under_investigation") return "brand";
  if (status === "resolved") return "success";
  return "neutral";
}

export interface IssueInput {
  category: IssueCategory;
  description: string;
  quantity_affected: number | null;
  part: string;
  size: string;
  image: File | null;
}

/** Client-side hint mirroring the server rules (the server validates again). */
export function issueInputError(input: IssueInput): string | null {
  const category = ISSUE_CATEGORIES.find((c) => c.value === input.category);
  if (!category) return "Choose a category.";
  if (input.description.trim().length < 3) return "Describe the issue.";
  if (category.needsQuantity && (input.quantity_affected === null || input.quantity_affected < 1)) return "Enter the quantity affected (at least 1).";
  if (input.quantity_affected !== null && (!Number.isInteger(input.quantity_affected) || input.quantity_affected < 1)) return "The quantity affected must be a whole number of at least 1.";
  if (input.category === "missing_parts" && !input.part) return "Choose the missing part.";
  if (input.image && !["image/jpeg", "image/png", "image/webp"].includes(input.image.type)) return "The image must be a JPG, PNG or WebP file.";
  if (input.image && input.image.size > 10 * 1024 * 1024) return "The image must not be larger than 10 MB.";
  return null;
}

export function issueBody(input: IssueInput, orderId?: number): FormData {
  const body = new FormData();
  if (orderId !== undefined) body.set("order_id", String(orderId));
  body.set("category", input.category);
  body.set("description", input.description.trim());
  if (input.quantity_affected !== null) body.set("quantity_affected", String(input.quantity_affected));
  if (input.part) body.set("part", input.part);
  if (input.size) body.set("size", input.size);
  if (input.image) body.set("image", input.image);
  return body;
}

export interface IssueListParams {
  page?: number;
  search?: string;
  status?: string;
  category?: string;
  unresolved?: boolean;
  blocking?: boolean;
  mine?: boolean;
  sort?: string;
}

export const issueKeys = {
  list: (p: object) => ["production-issues", "list", p] as const,
  detail: (id: number) => ["production-issues", "detail", id] as const,
  assignees: (id: number) => ["production-issues", "assignees", id] as const,
};

export function useIssues(params: IssueListParams) {
  return useQuery({
    queryKey: issueKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<ProductionIssue>>(
        "production-issues",
        {
          page: params.page,
          sort: params.sort,
          "filter[search]": params.search || undefined,
          "filter[status]": params.status || undefined,
          "filter[category]": params.category || undefined,
          "filter[unresolved]": params.unresolved ? 1 : undefined,
          "filter[blocking]": params.blocking ? 1 : undefined,
          "filter[mine]": params.mine ? 1 : undefined,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useIssue(id: number | null) {
  return useQuery({
    queryKey: issueKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: ProductionIssue }>(`production-issues/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useIssueAssignees(id: number, enabled: boolean) {
  return useQuery({
    queryKey: issueKeys.assignees(id),
    queryFn: ({ signal }) => api.get<{ data: { id: number; name: string }[] }>(`production-issues/${id}/assignees`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export function useIssueMutations(id: number) {
  const queryClient = useQueryClient();
  const done = (issue: ProductionIssue) => {
    queryClient.setQueryData(issueKeys.detail(id), issue);
    void queryClient.invalidateQueries({ queryKey: ["production-issues", "list"] });
    void queryClient.invalidateQueries({ queryKey: ["cutting-jobs"] });
  };
  const current = () => queryClient.getQueryData<ProductionIssue>(issueKeys.detail(id))?.version ?? 0;

  return {
    update: useMutation({
      mutationFn: (input: { assigned_to?: number | null; is_blocking?: boolean }) => api.patch<{ data: ProductionIssue }>(`production-issues/${id}`, { ...input, version: current() }).then((r) => r.data),
      onSuccess: done,
    }),
    transition: useMutation({
      mutationFn: (input: { to: IssueStatus; note: string; resolution: string }) =>
        api.post<{ data: ProductionIssue }>(`production-issues/${id}/transitions`, { to: input.to, note: input.note || null, resolution: input.resolution || null, version: current() }).then((r) => r.data),
      onSuccess: done,
    }),
  };
}

export function useReportOrderIssue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: IssueInput & { order_id: number }) => apiRequest<{ data: ProductionIssue }>("production-issues", { method: "POST", body: issueBody(input, input.order_id) }).then((r) => r.data),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["production-issues"] }),
  });
}
