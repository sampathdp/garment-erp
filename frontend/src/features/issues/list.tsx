"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, PageHeader, SelectField } from "@/components/ui/form-controls";
import { ChevronRightIcon, SearchIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { formatDateTime } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { ISSUE_CATEGORIES, ISSUE_STATUS_OPTIONS, type ProductionIssue, issueStatusTone, useIssues } from "./api";

/** Central production issue register. The server returns only the issues the user may see. */
export function IssueList() {
  const [params, setParams] = useState({ page: 1, search: "", status: "", category: "", unresolved: true, blocking: false, mine: false, sort: "-created_at" });
  const query = useIssues(useDebounced(params));

  const columns: Column<ProductionIssue>[] = [
    {
      key: "id",
      header: "Issue",
      cell: (i) => (
        <Link href={`/issues/view/?id=${i.id}`} className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition">
          #{i.id}
        </Link>
      ),
    },
    { key: "job", header: "Job No.", cell: (i) => <span className="font-mono text-xs">{i.job_no}</span> },
    { key: "page", header: "Company / Shop", cell: (i) => <span className="text-xs">{i.page.code}</span> },
    {
      key: "category",
      header: "Category",
      cell: (i) => (
        <div className="flex flex-col items-start gap-1 text-xs">
          <span className="font-medium text-ink">{i.category.label}</span>
          {i.is_blocking && i.status.unresolved && <Badge tone="danger">Blocking</Badge>}
        </div>
      ),
    },
    { key: "description", header: "Description", cell: (i) => <span className="line-clamp-2 max-w-xs text-xs">{i.description}</span> },
    { key: "qty", header: "Qty", cell: (i) => <span className="text-xs">{i.quantity_affected ?? "—"}</span> },
    {
      key: "reported",
      header: "Reported",
      sortKey: "created_at",
      cell: (i) => (
        <div className="flex flex-col text-[11px] text-muted">
          <span className="font-medium text-ink">{i.reported_by}</span>
          <span>{formatDateTime(i.reported_at)}</span>
        </div>
      ),
    },
    { key: "assigned", header: "Assigned to", cell: (i) => <span className="text-xs">{i.assigned_to?.name ?? "—"}</span> },
    { key: "status", header: "Status", sortKey: "status", cell: (i) => <Badge tone={issueStatusTone(i.status.value)} dot>{i.status.label}</Badge> },
    {
      key: "actions",
      header: "Actions",
      className: "text-right",
      cell: (i) => (
        <div className="flex items-center justify-end gap-1.5">
          <Link
            href={`/issues/view/?id=${i.id}`}
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
    <RequirePermission anyOf={["production.view", "production.issues.manage", "production.issues.report"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Production issues" description="Damaged pieces, incorrect cuts, missing parts and other production problems. Blocking issues stop cutting and job completion until resolved." />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:flex-wrap lg:items-end">
          <div className="w-full lg:max-w-xs">
            <TextField label="Search" placeholder="Job no. or description…" icon={<SearchIcon size={16} />} maxLength={100} value={params.search} onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))} />
          </div>
          <SelectField label="Status" options={[{ value: "", label: "All statuses" }, ...ISSUE_STATUS_OPTIONS]} value={params.status} onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value }))} />
          <SelectField label="Category" options={[{ value: "", label: "All categories" }, ...ISSUE_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))]} value={params.category} onChange={(e) => setParams((p) => ({ ...p, page: 1, category: e.target.value }))} />
          <div className="flex flex-wrap gap-4 pb-2">
            <Checkbox label="Unresolved only" checked={params.unresolved} onChange={(e) => setParams((p) => ({ ...p, page: 1, unresolved: e.target.checked }))} />
            <Checkbox label="Blocking only" checked={params.blocking} onChange={(e) => setParams((p) => ({ ...p, page: 1, blocking: e.target.checked }))} />
            <Checkbox label="Assigned to me" checked={params.mine} onChange={(e) => setParams((p) => ({ ...p, page: 1, mine: e.target.checked }))} />
          </div>
        </div>
        <DataTable
          caption="Production issues"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(i) => i.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No production issues"
        />
      </div>
    </RequirePermission>
  );
}
