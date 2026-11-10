"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, PageHeader, SelectField } from "@/components/ui/form-controls";
import { ExternalLinkIcon, SearchIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { formatBusinessDate } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { BULK_STATUS_OPTIONS, type BulkJob, bulkStatusTone, useBulkJobs } from "./api";

type Variant = "manage" | "mine";

const COPY: Record<Variant, { title: string; description: string; permission: string }> = {
  manage: {
    title: "Bulk management",
    description: "Every bulk job in your scope, earliest due date first. Assign each job to an internal Bulk Designer or an external producer.",
    permission: "bulk.view",
  },
  mine: {
    title: "My bulk jobs",
    description: "Bulk jobs assigned to you, earliest due date first. Open a job to prepare the bulk, enter measurements and complete the checklist.",
    permission: "bulk.view_assigned",
  },
};

/**
 * The two bulk dashboards share one list endpoint: the server returns every job in scope to
 * holders of bulk.view and only assigned jobs to bulk designers.
 */
export function BulkDashboard({ variant }: { variant: Variant }) {
  const [params, setParams] = useState({ page: 1, search: "", status: "", mode: "", open: true, sort: "due_date" });
  const query = useBulkJobs(useDebounced(params));
  const copy = COPY[variant];

  const columns: Column<BulkJob>[] = [
    {
      key: "job",
      header: "Job No.",
      cell: (j) => (
        <Link href={`/bulk-jobs/view/?id=${j.id}`} className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition">
          {j.job_no}
        </Link>
      ),
    },
    {
      key: "preview",
      header: "Design",
      cell: (j) =>
        j.design_preview_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
          <img src={j.design_preview_url} alt={`Approved design of ${j.job_no}`} className="h-10 w-10 rounded-md border border-line object-cover" />
        ) : (
          <span className="text-xs text-muted">—</span>
        ),
    },
    { key: "customer", header: "Customer", cell: (j) => <span className="font-medium text-ink">{j.order.customer}</span> },
    { key: "where", header: "Branch / Page", cell: (j) => <span className="text-xs text-muted">{j.branch} / {j.page.code}</span> },
    {
      key: "due",
      header: "Due date",
      sortKey: "due_date",
      cell: (j) => (
        <div className="flex items-center gap-1.5">
          <span className="text-xs font-medium text-ink">{formatBusinessDate(j.order.due_date)}</span>
          {j.order.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}
        </div>
      ),
    },
    { key: "category", header: "Category", cell: (j) => j.product_category },
    { key: "qty", header: "Qty", cell: (j) => <span className="font-semibold text-ink">{j.order.quantity}</span> },
    { key: "material", header: "Material", cell: (j) => <span className="text-xs">{j.material ?? "—"}</span> },
    {
      key: "final",
      header: "Final design",
      cell: (j) =>
        j.final_design_link ? (
          <a className="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline" href={j.final_design_link} target="_blank" rel="noopener noreferrer nofollow">
            Open <ExternalLinkIcon size={12} />
          </a>
        ) : (
          <span className="text-xs text-muted">Files only</span>
        ),
    },
    {
      key: "list",
      header: "Confirmed list",
      cell: (j) =>
        j.list ? (
          <Link href={`/bulk-jobs/view/?id=${j.id}#list`} className="text-xs font-medium text-brand hover:underline">
            v{j.list.version_no} · {j.list.total_quantity} pcs{j.list.status !== "locked" ? " (superseded)" : ""}
          </Link>
        ) : (
          <span className="text-xs text-muted">—</span>
        ),
    },
    ...(variant === "manage"
      ? [
          {
            key: "assigned",
            header: "Assigned to",
            cell: (j: BulkJob) =>
              j.mode === null ? (
                <span className="text-xs font-semibold text-danger">Not assigned</span>
              ) : (
                <span className="inline-flex items-center gap-1.5 text-xs text-ink">
                  <Badge tone={j.mode === "external" ? "warning" : "neutral"}>{j.mode === "external" ? "External" : "Internal"}</Badge>
                  {j.mode === "external" ? j.supplier?.name : j.bulk_designer?.name}
                </span>
              ),
          } satisfies Column<BulkJob>,
        ]
      : []),
    { key: "status", header: "Status", cell: (j) => <Badge tone={bulkStatusTone(j.status.value)} dot>{j.status.label}</Badge> },
  ];

  return (
    <RequirePermission anyOf={[copy.permission]}>
      <div className="flex flex-col gap-6">
        <PageHeader title={copy.title} description={copy.description} />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:items-end">
          <div className="w-full lg:max-w-sm">
            <TextField label="Search" placeholder="Job no. or customer…" icon={<SearchIcon size={16} />} maxLength={100} value={params.search} onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))} />
          </div>
          <SelectField label="Status" options={[{ value: "", label: "All statuses" }, ...BULK_STATUS_OPTIONS]} value={params.status} onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value }))} />
          {variant === "manage" && (
            <SelectField
              label="Assignment"
              options={[
                { value: "", label: "All" },
                { value: "unassigned", label: "Not assigned" },
                { value: "internal", label: "Internal" },
                { value: "external", label: "External" },
              ]}
              value={params.mode}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, mode: e.target.value }))}
            />
          )}
          <div className="pb-2">
            <Checkbox label="Hide completed jobs" checked={params.open} onChange={(e) => setParams((p) => ({ ...p, page: 1, open: e.target.checked }))} />
          </div>
        </div>
        <DataTable
          caption={copy.title}
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(j) => j.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle={variant === "mine" ? "No bulk jobs assigned to you" : "No bulk jobs"}
        />
      </div>
    </RequirePermission>
  );
}
