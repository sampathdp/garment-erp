"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, PageHeader, SelectField } from "@/components/ui/form-controls";
import { ChevronRightIcon, ExternalLinkIcon, SearchIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { formatBusinessDate } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { PRINT_STATUS_OPTIONS, type PrintJob, type PrintMeasurement, printStatusTone, usePrintJobs } from "./api";

const meter = (m: PrintMeasurement | null) => (m === null || m.value === null ? <span className="text-xs text-muted">—</span> : <span className="text-xs font-medium text-ink">{m.value} {m.unit}</span>);

/**
 * Printing dashboard. Only jobs whose bulk is completed exist here: the server opens a printing
 * job at bulk completion and never lists one for an incomplete bulk job.
 */
export function PrintingDashboard() {
  const [params, setParams] = useState({ page: 1, search: "", status: "", mode: "", open: true, sort: "due_date" });
  const query = usePrintJobs(useDebounced(params));

  const columns: Column<PrintJob>[] = [
    {
      key: "job",
      header: "Job No.",
      cell: (j) => (
        <Link href={`/printing/view/?id=${j.id}`} className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition">
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
    {
      key: "customer",
      header: "Customer / due",
      sortKey: "due_date",
      cell: (j) => (
        <div className="flex flex-col">
          <span className="font-medium text-ink">{j.order.customer}</span>
          <span className="flex items-center gap-1.5 text-[11px] text-muted">
            {formatBusinessDate(j.order.due_date)} {j.order.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}
          </span>
        </div>
      ),
    },
    { key: "material", header: "Material", cell: (j) => <span className="text-xs">{j.material ?? "—"}</span> },
    { key: "bulk_meter", header: "Bulk meter", cell: (j) => meter(j.bulk_meter) },
    { key: "patch_meter", header: "Patch meter", cell: (j) => meter(j.patch_meter) },
    { key: "collar", header: "Collar", cell: (j) => meter(j.collar_meter) },
    { key: "qty", header: "Qty", cell: (j) => <span className="font-semibold text-ink">{j.quantity}</span> },
    {
      key: "files",
      header: "Bulk files",
      cell: (j) => (
        <div className="flex flex-col gap-0.5 text-xs">
          <span className="text-muted">{j.bulk.files.length === 0 ? "No files" : `${j.bulk.files.length} file(s)`}</span>
          {j.bulk.transfer_url && (
            <a className="inline-flex items-center gap-1 font-semibold text-brand hover:underline" href={j.bulk.transfer_url} target="_blank" rel="noopener noreferrer nofollow">
              WeTransfer <ExternalLinkIcon size={12} />
            </a>
          )}
        </div>
      ),
    },
    { key: "mode", header: "Type", cell: (j) => <Badge tone={j.mode === "external" ? "warning" : "neutral"}>{j.mode === "external" ? "External" : "Internal"}</Badge> },
    {
      key: "docs",
      header: "Documents",
      cell: (j) => (
        <div className="flex flex-col gap-0.5 text-xs">
          <Link href={`/printing/view/?id=${j.id}#job-card`} className="font-medium text-brand hover:underline">Job Card</Link>
          <Link href={`/printing/view/?id=${j.id}#name-list`} className="font-medium text-brand hover:underline">List v{j.list.version_no} · {j.list.total_quantity} pcs</Link>
        </div>
      ),
    },
    { key: "status", header: "Status", cell: (j) => <Badge tone={printStatusTone(j.status.value)} dot>{j.status.label}</Badge> },
    {
      key: "actions",
      header: "Actions",
      className: "text-right",
      cell: (j) => (
        <div className="flex items-center justify-end gap-1.5">
          <Link
            href={`/printing/view/?id=${j.id}`}
            className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-brand/40 hover:bg-subtle hover:text-brand transition shrink-0"
          >
            <span>View</span>
            <ChevronRightIcon size={13} />
          </Link>
        </div>
      ),
    },
  ];

  return (
    <RequirePermission anyOf={["printing.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Printing" description="Jobs whose bulk is completed, earliest due date first. Review the information, send for printing and follow the sample approval." />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:items-end">
          <div className="w-full lg:max-w-sm">
            <TextField label="Search" placeholder="Job no. or customer…" icon={<SearchIcon size={16} />} maxLength={100} value={params.search} onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))} />
          </div>
          <SelectField label="Status" options={[{ value: "", label: "All statuses" }, ...PRINT_STATUS_OPTIONS]} value={params.status} onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value }))} />
          <SelectField
            label="Type"
            options={[
              { value: "", label: "All" },
              { value: "internal", label: "Internal" },
              { value: "external", label: "External" },
            ]}
            value={params.mode}
            onChange={(e) => setParams((p) => ({ ...p, page: 1, mode: e.target.value }))}
          />
          <div className="pb-2">
            <Checkbox label="Hide completed printing" checked={params.open} onChange={(e) => setParams((p) => ({ ...p, page: 1, open: e.target.checked }))} />
          </div>
        </div>
        <DataTable
          caption="Printing jobs"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(j) => j.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No jobs ready for printing"
        />
      </div>
    </RequirePermission>
  );
}
