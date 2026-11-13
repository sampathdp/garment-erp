"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, PageHeader, SelectField } from "@/components/ui/form-controls";
import { ChevronRightIcon, ExternalLinkIcon, SearchIcon, ShirtIcon } from "@/components/ui/icons";
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
      header: "Job & Design",
      cell: (j) => (
        <div className="flex items-center gap-3 py-0.5">
          {j.design_preview_url ? (
            // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
            <img
              src={j.design_preview_url}
              alt={`Approved design of ${j.job_no}`}
              className="h-11 w-11 shrink-0 rounded-lg border border-line object-cover bg-subtle"
            />
          ) : (
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-line bg-subtle text-muted">
              <ShirtIcon size={20} />
            </div>
          )}
          <div className="flex flex-col gap-1 min-w-0">
            <Link
              href={`/bulk-jobs/view/?id=${j.id}`}
              className="font-mono text-xs font-bold text-brand bg-brand/10 hover:bg-brand/20 px-2 py-0.5 rounded whitespace-nowrap transition inline-block w-fit"
            >
              {j.job_no}
            </Link>
            <div className="flex items-center gap-1.5 text-xs text-muted">
              <span className="rounded bg-subtle px-1.5 py-0.5 text-[10px] font-semibold text-ink">
                {j.page.code}
              </span>
            </div>
          </div>
        </div>
      ),
    },
    {
      key: "customer",
      header: "Customer",
      cell: (j) => (
        <div className="flex flex-col">
          <span className="font-semibold text-ink text-sm leading-snug">{j.order.customer}</span>
          <span className="text-xs text-muted font-mono">Order #{j.order.order_no}</span>
        </div>
      ),
    },
    {
      key: "product",
      header: "Product & Fabric",
      cell: (j) => (
        <div className="flex flex-col">
          <div className="flex items-center gap-1.5">
            <span className="font-bold text-ink text-sm">{j.order.quantity} pcs</span>
            <span className="text-muted">·</span>
            <span className="font-medium text-ink text-xs">{j.product_category}</span>
          </div>
          <span className="text-xs text-muted truncate max-w-[200px]" title={j.material ?? undefined}>
            {j.material ? j.material : "Material not specified"}
          </span>
        </div>
      ),
    },
    {
      key: "due",
      header: "Due Date",
      sortKey: "due_date",
      cell: (j) => (
        <div className="flex flex-col">
          <div className="flex items-center gap-1.5">
            <span className="text-xs font-semibold text-ink whitespace-nowrap">
              {formatBusinessDate(j.order.due_date)}
            </span>
            {j.order.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}
          </div>
        </div>
      ),
    },
    ...(variant === "manage"
      ? [
          {
            key: "assigned",
            header: "Assignment",
            cell: (j: BulkJob) =>
              j.mode === null ? (
                <Badge tone="danger">Unassigned</Badge>
              ) : (
                <div className="flex flex-col gap-0.5">
                  <div className="flex items-center gap-1.5">
                    <Badge tone={j.mode === "external" ? "warning" : "brand"}>
                      {j.mode === "external" ? "External" : "Internal"}
                    </Badge>
                    <span
                      className="text-xs font-medium text-ink truncate max-w-[140px]"
                      title={j.mode === "external" ? j.supplier?.name : j.bulk_designer?.name}
                    >
                      {j.mode === "external" ? (j.supplier?.name ?? "—") : (j.bulk_designer?.name ?? "—")}
                    </span>
                  </div>
                </div>
              ),
          } satisfies Column<BulkJob>,
        ]
      : []),
    {
      key: "status",
      header: "Status & Assets",
      cell: (j) => (
        <div className="flex flex-col gap-1">
          <div>
            <Badge tone={bulkStatusTone(j.status.value)} dot>
              {j.status.label}
            </Badge>
          </div>
          <div className="flex items-center gap-2 text-[11px] text-muted">
            {j.final_design_link ? (
              <a
                href={j.final_design_link}
                target="_blank"
                rel="noopener noreferrer nofollow"
                className="inline-flex items-center gap-1 text-brand font-medium hover:underline"
              >
                <span>Artwork</span>
                <ExternalLinkIcon size={11} />
              </a>
            ) : (
              <span className="text-muted/60">Artwork pending</span>
            )}
            {j.list && (
              <>
                <span>·</span>
                <Link
                  href={`/bulk-jobs/view/?id=${j.id}#list`}
                  className="text-muted hover:text-ink font-medium hover:underline transition"
                >
                  Roster v{j.list.version_no}
                </Link>
              </>
            )}
          </div>
        </div>
      ),
    },
    {
      key: "actions",
      header: "Actions",
      className: "text-right",
      cell: (j) => (
        <div className="flex items-center justify-end">
          <Link
            href={`/bulk-jobs/view/?id=${j.id}`}
            className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-brand/40 hover:bg-subtle hover:text-brand transition whitespace-nowrap"
          >
            <span>Open Job</span>
            <ChevronRightIcon size={14} />
          </Link>
        </div>
      ),
    },
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
