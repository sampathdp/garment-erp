import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, type Paginated } from "@/lib/api-client";
import type { BulkGarmentList, JobCard } from "@/features/bulk/api";
import type { IssueInput, ProductionIssue } from "@/features/issues/api";
import { issueBody } from "@/features/issues/api";

export type CuttingStatus = "pending_assignment" | "assigned" | "in_progress" | "completed";
export type QualityResult = "pass" | "fail" | "na";

export interface CuttingChecklistItem {
  id: number;
  label: string;
  is_required: boolean;
  is_completed: boolean;
  result: QualityResult | null;
  notes: string | null;
  responsible: string | null;
  responsible_user_id: number | null;
  completed_by: string | null;
  completed_at: string | null;
}

export interface CuttingChecklist {
  id: number;
  items: CuttingChecklistItem[];
  client_confirmation: { recorded_by: string | null; recorded_at: string; channel: string | null; comment: string | null; evidence_url: string | null } | null;
}

export interface CuttingQuantityRow {
  id: number;
  size: string;
  expected: number;
  cut: number;
  missing: number;
  updated_by: string | null;
  updated_at: string | null;
}

export interface CuttingIssueSummary {
  id: number;
  category: { value: string; label: string };
  status: { value: string; label: string };
  description: string;
  quantity_affected: number | null;
  part: string | null;
  size: string | null;
  is_blocking: boolean;
  reported_by: string;
  reported_at: string | null;
  assigned_to: string | null;
}

export interface CuttingTotals {
  expected: number;
  cut: number;
  missing: number;
  defective: number;
  good: number;
}

export interface CuttingJob {
  id: number;
  version: number;
  status: { value: CuttingStatus; label: string };
  job_no: string;
  order: { id: number; customer: string; due_date: string; is_urgent: boolean; status: { value: string; label: string } };
  branch: string;
  page: { code: string; name: string };
  product_category: string;
  material: string | null;
  quantity: number;
  design_preview_url: string | null;
  design_version_no: number;
  list: { version_no: number; total_quantity: number; status: string };
  printing: { id: number; printed_quantity: number | null; completed_at: string | null; printing_company: string | null; mode: string; gate_passes: { gate_pass_no: string; gate_pass_date: string }[] };
  cutter: { id: number; name: string } | null;
  assigned_at: string | null;
  expected_completion_date: string | null;
  totals: CuttingTotals;
  open_issue_count: number;
  completed_at: string | null;
  created_at: string | null;
  quantities?: CuttingQuantityRow[];
  assignment?: { assigned_by: string | null; notes: string | null; history: { cutter: string; expected_completion_date: string; notes: string | null; reason: string | null; by: string; at: string | null }[] };
  checklist?: CuttingChecklist | null;
  quality_checks?: CuttingChecklist | null;
  issues?: CuttingIssueSummary[];
  completion?: { completed_by: string | null; completed_at: string | null; notes: string | null; blockers: Partial<Record<"quantities" | "checklist" | "quality_checks" | "issues", string[]>> };
  history?: { from: string | null; to: string; note: string | null; by: string; at: string | null }[];
  abilities?: { assign: boolean; record_work: boolean; record_quality_check: boolean; confirm_checklist: boolean; complete: boolean; report_issue: boolean };
}

export type CuttingJobCard = JobCard & { cutting: { id: number; status: { value: CuttingStatus; label: string } } };

export interface CuttingListParams {
  page?: number;
  search?: string;
  status?: string;
  open?: boolean;
  mine?: boolean;
  sort?: string;
}

export const CUTTING_STATUS_OPTIONS: { value: CuttingStatus; label: string }[] = [
  { value: "pending_assignment", label: "Pending Assignment" },
  { value: "assigned", label: "Assigned" },
  { value: "in_progress", label: "Cutting In Progress" },
  { value: "completed", label: "Cutting Completed" },
];

export const BLOCKER_LABELS: Record<string, string> = {
  quantities: "Quantities",
  checklist: "Cutting checklist",
  quality_checks: "Quality checks",
  issues: "Blocking issues",
};

export function cuttingStatusTone(status: CuttingStatus): "neutral" | "success" | "brand" | "warning" {
  if (status === "completed") return "success";
  if (status === "pending_assignment") return "warning";
  if (status === "in_progress") return "brand";
  return "neutral";
}

export const cuttingKeys = {
  list: (p: object) => ["cutting-jobs", "list", p] as const,
  detail: (id: number) => ["cutting-jobs", "detail", id] as const,
  jobCard: (id: number) => ["cutting-jobs", "job-card", id] as const,
  nameList: (id: number) => ["cutting-jobs", "name-list", id] as const,
  cutters: (id: number) => ["cutting-jobs", "cutters", id] as const,
};

export function useCuttingJobs(params: CuttingListParams) {
  return useQuery({
    queryKey: cuttingKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<CuttingJob>>(
        "cutting-jobs",
        {
          page: params.page,
          sort: params.sort,
          "filter[search]": params.search || undefined,
          "filter[status]": params.status || undefined,
          "filter[open]": params.open ? 1 : undefined,
          "filter[mine]": params.mine ? 1 : undefined,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useCuttingJob(id: number | null) {
  return useQuery({
    queryKey: cuttingKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: CuttingJob }>(`cutting-jobs/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useCuttingJobCard(id: number, enabled: boolean) {
  return useQuery({
    queryKey: cuttingKeys.jobCard(id),
    queryFn: ({ signal }) => api.get<{ data: CuttingJobCard }>(`cutting-jobs/${id}/job-card`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export function useCuttingNameList(id: number, enabled: boolean) {
  return useQuery({
    queryKey: cuttingKeys.nameList(id),
    queryFn: ({ signal }) => api.get<{ data: BulkGarmentList }>(`cutting-jobs/${id}/name-list`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export function useCutters(id: number, enabled: boolean) {
  return useQuery({
    queryKey: cuttingKeys.cutters(id),
    queryFn: ({ signal }) => api.get<{ data: { id: number; name: string }[] }>(`cutting-jobs/${id}/cutters`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export interface AssignInput {
  cutter_id: number;
  expected_completion_date: string;
  notes: string;
  reason: string;
}

/**
 * Client-side hint for the cut quantity of one size (the server validates again):
 * a whole number from 0 to the expected quantity.
 */
export function cutQuantityError(raw: string, expected: number): string | null {
  if (raw.trim() === "") return "Enter a quantity.";
  if (!/^\d+$/.test(raw.trim())) return "Use a whole number of 0 or more.";
  const n = Number(raw);
  if (n > expected) return `Cannot exceed the expected ${expected}.`;
  return null;
}

/** Totals of a quantity form: cut and missing items. */
export function cutTotals(rows: { expected: number; cut: number }[]): { expected: number; cut: number; missing: number } {
  const expected = rows.reduce((s, r) => s + r.expected, 0);
  const cut = rows.reduce((s, r) => s + r.cut, 0);
  return { expected, cut, missing: Math.max(0, expected - cut) };
}

export function useCuttingMutations(id: number) {
  const queryClient = useQueryClient();
  const done = (job: CuttingJob) => {
    queryClient.setQueryData(cuttingKeys.detail(id), job);
    void queryClient.invalidateQueries({ queryKey: ["cutting-jobs", "list"] });
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
  };
  const current = () => queryClient.getQueryData<CuttingJob>(cuttingKeys.detail(id))?.version ?? 0;
  const send = (method: "POST" | "PUT" | "PATCH", path: string, body: unknown) => apiRequest<{ data: CuttingJob }>(`cutting-jobs/${id}/${path}`, { method, body }).then((r) => r.data);

  return {
    assign: useMutation({
      mutationFn: (input: AssignInput) => send("POST", "assignment", { ...input, notes: input.notes || null, reason: input.reason || null, version: current() }),
      onSuccess: done,
    }),
    quantities: useMutation({
      mutationFn: (rows: { id: number; cut: number }[]) => send("PUT", "quantities", { quantities: rows, version: current() }),
      onSuccess: done,
    }),
    checklistItem: useMutation({
      mutationFn: (input: { item: number; is_completed?: boolean; notes?: string | null; responsible_user_id?: number | null }) => {
        const { item, ...body } = input;
        return send("PATCH", `checklist/items/${item}`, body);
      },
      onSuccess: done,
    }),
    qualityCheck: useMutation({
      mutationFn: (input: { item: number; result: QualityResult; notes: string }) => send("PATCH", `quality-checks/${input.item}`, { result: input.result, notes: input.notes || null }),
      onSuccess: done,
    }),
    confirm: useMutation({
      mutationFn: (input: { channel: string; comment: string; evidence: File | null }) => {
        const body = new FormData();
        body.set("channel", input.channel);
        if (input.comment.trim()) body.set("comment", input.comment.trim());
        if (input.evidence) body.set("evidence", input.evidence);
        return apiRequest<{ data: CuttingJob }>(`cutting-jobs/${id}/checklist/client-confirmation`, { method: "POST", body }).then((r) => r.data);
      },
      onSuccess: done,
    }),
    complete: useMutation({ mutationFn: (notes: string) => send("POST", "complete", { notes: notes || null, version: current() }), onSuccess: done }),
    reportIssue: useMutation({
      mutationFn: (input: IssueInput) => apiRequest<{ data: ProductionIssue }>(`cutting-jobs/${id}/issues`, { method: "POST", body: issueBody(input) }).then((r) => r.data),
      onSuccess: () => {
        void queryClient.invalidateQueries({ queryKey: cuttingKeys.detail(id) });
        void queryClient.invalidateQueries({ queryKey: ["production-issues"] });
      },
    }),
  };
}
