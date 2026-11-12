"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { DataTable, type Column } from "@/components/ui/data-table";
import { Badge, Checkbox, FormError, PageHeader } from "@/components/ui/form-controls";
import { RequirePermission } from "@/components/ui/record-page";
import { useToast } from "@/components/ui/toast";
import { api, type Paginated } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import { apiErrorMessage } from "@/features/issues/issue-form";

export interface SewingJob {
  id: number;
  version: number;
  status: { value: "pending" | "in_progress" | "completed"; label: string };
  job_no: string;
  order: { id: number; customer: string; due_date: string; is_urgent: boolean; status: { value: string; label: string } };
  branch: string;
  page: string;
  product_category: string;
  received_quantity: number;
  sewn_quantity: number;
  started_by: string | null;
  started_at: string | null;
  completed_by: string | null;
  completed_at: string | null;
  abilities: { start: boolean; complete: boolean };
}

function Actions({ job }: { job: SewingJob }) {
  const queryClient = useQueryClient();
  const { notify } = useToast();
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["sewing-jobs"] });
  const start = useMutation({ mutationFn: () => api.post(`sewing-jobs/${job.id}/start`, { version: job.version }), onSuccess: refresh });
  const complete = useMutation({
    mutationFn: () => api.post(`sewing-jobs/${job.id}/complete`, { version: job.version, sewn_quantity: job.received_quantity }),
    onSuccess: () => (refresh(), notify("Sewing completed. The job is ready for delivery.", "success")),
  });

  return (
    <div className="flex flex-col items-start gap-1">
      <FormError message={apiErrorMessage(start.error) ?? apiErrorMessage(complete.error)} />
      {job.abilities.start && <Button size="sm" variant="secondary" loading={start.isPending} onClick={() => start.mutate()}>Start sewing</Button>}
      {job.abilities.complete && <Button size="sm" loading={complete.isPending} onClick={() => complete.mutate()}>All {job.received_quantity} sewn — complete</Button>}
    </div>
  );
}

/** Minimal sewing step (Phase 08): pieces received from cutting are sewn; completion releases the job for delivery. */
export function SewingScreen() {
  const [params, setParams] = useState({ page: 1, open: true });
  const query = useQuery({
    queryKey: ["sewing-jobs", params],
    queryFn: ({ signal }) => api.get<Paginated<SewingJob>>("sewing-jobs", { page: params.page, "filter[open]": params.open ? 1 : undefined }, signal),
    placeholderData: keepPreviousData,
  });

  const columns: Column<SewingJob>[] = [
    { key: "job", header: "Job No.", cell: (j) => <span className="font-mono text-xs font-bold text-ink">{j.job_no}</span> },
    {
      key: "customer",
      header: "Customer / due",
      cell: (j) => (
        <div className="flex flex-col">
          <span className="font-medium text-ink">{j.order.customer}</span>
          <span className="flex items-center gap-1.5 text-[11px] text-muted">{formatBusinessDate(j.order.due_date)} {j.order.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}</span>
        </div>
      ),
    },
    { key: "page", header: "Company / Shop", cell: (j) => <span className="text-xs">{j.page}</span> },
    { key: "category", header: "Category", cell: (j) => <span className="text-xs">{j.product_category}</span> },
    { key: "qty", header: "Pieces", cell: (j) => <span className="text-xs">{j.sewn_quantity} / {j.received_quantity}</span> },
    {
      key: "status",
      header: "Status",
      cell: (j) => (
        <div className="flex flex-col gap-0.5">
          <Badge tone={j.status.value === "completed" ? "success" : j.status.value === "in_progress" ? "brand" : "warning"} dot>{j.status.label}</Badge>
          {j.completed_at && <span className="text-[11px] text-muted">{j.completed_by} · {formatDateTime(j.completed_at)}</span>}
        </div>
      ),
    },
    { key: "actions", header: "", cell: (j) => <Actions job={j} /> },
  ];

  return (
    <RequirePermission anyOf={["production.view", "sewing.manage"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Sewing" description="Jobs whose cutting is completed. Completing sewing makes the job ready for delivery." />
        <div className="flex rounded-xl border border-line bg-surface p-4 shadow-xs">
          <Checkbox label="Hide completed" checked={params.open} onChange={(e) => setParams({ page: 1, open: e.target.checked })} />
        </div>
        <DataTable
          caption="Sewing jobs"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(j) => j.id}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No sewing jobs"
        />
      </div>
    </RequirePermission>
  );
}
