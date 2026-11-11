"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, PageHeader, SelectField } from "@/components/ui/form-controls";
import { SearchIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { useAuth } from "@/features/auth/auth-provider";
import { formatBusinessDate } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { CUTTING_STATUS_OPTIONS, type CuttingJob, cuttingStatusTone, useCuttingJobs } from "./api";

/**
 * Cutting dashboard. A cutting job exists only once printing is completed and the gate pass is
 * recorded. Operation Admins see every job in scope; Cutting Staff only the jobs assigned to them.
 */
export function CuttingDashboard() {
  const { can } = useAuth();
  const [params, setParams] = useState({ page: 1, search: "", status: "", open: true, mine: false, sort: "due_date" });
  const query = useCuttingJobs(useDebounced(params));
  const seesAll = can("cutting.view");

  const columns: Column<CuttingJob>[] = [
    {
      key: "job",
      header: "Job No.",
      cell: (j) => (
        <Link href={`/cutting/view/?id=${j.id}`} className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition">
          {j.job_no}
        </Link>
      ),
    },
    { key: "page", header: "Branch / page", cell: (j) => <span className="text-xs">{j.branch} · {j.page.code}</span> },
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
    { key: "category", header: "Category", cell: (j) => <span className="text-xs">{j.product_category}</span> },
    {
      key: "qty",
      header: "Qty (cut)",
      cell: (j) => (
        <span className="text-xs">
          <strong className="text-ink">{j.quantity}</strong> <span className="text-muted">({j.totals.cut} cut{j.totals.missing > 0 ? `, ${j.totals.missing} missing` : ""})</span>
        </span>
      ),
    },
    {
      key: "docs",
      header: "Documents",
      cell: (j) => (
        <div className="flex flex-col gap-0.5 text-xs">
          <Link href={`/cutting/view/?id=${j.id}#job-card`} className="font-medium text-brand hover:underline">Job Card</Link>
          <Link href={`/cutting/view/?id=${j.id}#name-list`} className="font-medium text-brand hover:underline">List v{j.list.version_no} · {j.list.total_quantity} pcs</Link>
        </div>
      ),
    },
    {
      key: "printing",
      header: "Printing",
      cell: (j) => (
        <div className="flex flex-col text-[11px] text-muted">
          <span>{j.printing.printed_quantity ?? "—"} printed{j.printing.printing_company ? ` · ${j.printing.printing_company}` : ""}</span>
          {j.printing.gate_passes[0] && <span className="font-mono">GP {j.printing.gate_passes[0].gate_pass_no}</span>}
        </div>
      ),
    },
    {
      key: "cutter",
      header: "Cutter / expected",
      sortKey: "expected_completion_date",
      cell: (j) => (
        <div className="flex flex-col text-xs">
          <span className="font-medium text-ink">{j.cutter?.name ?? "Unassigned"}</span>
          {j.expected_completion_date && <span className="text-[11px] text-muted">by {formatBusinessDate(j.expected_completion_date)}</span>}
        </div>
      ),
    },
    {
      key: "status",
      header: "Status",
      cell: (j) => (
        <div className="flex flex-col items-start gap-1">
          <Badge tone={cuttingStatusTone(j.status.value)} dot>{j.status.label}</Badge>
          {j.open_issue_count > 0 && <Badge tone="danger">{j.open_issue_count} open issue{j.open_issue_count > 1 ? "s" : ""}</Badge>}
        </div>
      ),
    },
  ];

  return (
    <RequirePermission anyOf={["cutting.view", "cutting.view_assigned"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title={seesAll ? "Cutting" : "My cutting jobs"}
          description="Printed jobs with a recorded gate pass, earliest due date first. Assign cutters, record quantities, complete the checklist and quality checks."
        />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:items-end">
          <div className="w-full lg:max-w-sm">
            <TextField label="Search" placeholder="Job no. or customer…" icon={<SearchIcon size={16} />} maxLength={100} value={params.search} onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))} />
          </div>
          <SelectField label="Status" options={[{ value: "", label: "All statuses" }, ...CUTTING_STATUS_OPTIONS]} value={params.status} onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value }))} />
          <div className="flex gap-4 pb-2">
            <Checkbox label="Hide completed" checked={params.open} onChange={(e) => setParams((p) => ({ ...p, page: 1, open: e.target.checked }))} />
            {seesAll && <Checkbox label="Assigned to me" checked={params.mine} onChange={(e) => setParams((p) => ({ ...p, page: 1, mine: e.target.checked }))} />}
          </div>
        </div>
        <DataTable
          caption="Cutting jobs"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(j) => j.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No cutting jobs"
        />
      </div>
    </RequirePermission>
  );
}
