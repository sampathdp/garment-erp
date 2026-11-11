"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, PageHeader, Section, SelectField } from "@/components/ui/form-controls";
import { RequirePermission } from "@/components/ui/record-page";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { api } from "@/lib/api-client";
import { formatBusinessDate } from "@/lib/format";

type Cell = string | number | boolean | null | { formatted: string } | { label: string } | Record<string, unknown>;
type Row = Record<string, Cell>;

interface ReportPayload {
  key: string;
  title: string;
  filters: { from: string; to: string; days: number };
  rows?: Row[];
  summary?: Record<string, number | null>;
  total?: number;
  totals?: Row[] | null;
  by_status?: Record<string, number>;
  average_resolution_hours?: number | null;
  generated_at?: string;
}

const HIDDEN = new Set(["id", "status", "bucket", "category"]);

/** Renders any report cell: money objects, labelled objects, booleans and plain values. */
export function cellText(value: Cell): string {
  if (value === null || value === undefined) return "—";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "object") {
    if ("formatted" in value && typeof value.formatted === "string") return value.formatted;
    if ("label" in value && typeof value.label === "string") return value.label;
    return "—";
  }
  return String(value);
}

export const headerText = (key: string) => key.replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase());

function RowsTable({ rows, caption }: { rows: Row[]; caption: string }) {
  if (rows.length === 0) return <p className="text-xs text-muted">No data for the selected period.</p>;
  const columns = Object.keys(rows[0]).filter((k) => !HIDDEN.has(k) || (k === "status" && typeof rows[0][k] === "object"));

  return (
    <div className="overflow-x-auto rounded-xl border border-line">
      <table className="w-full text-xs">
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-subtle text-left text-muted"><tr>{columns.map((c) => <th key={c} className="px-3 py-2">{headerText(c)}</th>)}</tr></thead>
        <tbody className="divide-y divide-line">
          {rows.map((r, i) => (
            <tr key={i}>{columns.map((c) => <td key={c} className="px-3 py-1.5">{c === "due_date" && typeof r[c] === "string" ? formatBusinessDate(r[c] as string) : cellText(r[c])}</td>)}</tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Operational reports (reports.view). Aggregates are cached on the server; lists are paginated. */
export function ReportsScreen() {
  const search = useSearchParams();
  const catalogue = useQuery({ queryKey: ["reports", "catalogue"], queryFn: ({ signal }) => api.get<{ data: { reports: { key: string; title: string; paginated: boolean }[] } }>("reports", undefined, signal).then((r) => r.data.reports) });
  const [report, setReport] = useState(search.get("report") ?? "jobs-by-status");
  const [filters, setFilters] = useState({ from: "", to: "", days: "14" });
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ["reports", report, filters, page],
    queryFn: ({ signal }) =>
      api.get<{ data: ReportPayload; meta?: { page: number; last_page: number; total: number } }>(`reports/${report}`, {
        page, "filter[from]": filters.from || undefined, "filter[to]": filters.to || undefined, "filter[days]": report === "upcoming-due" ? filters.days : undefined,
      }, signal),
    placeholderData: keepPreviousData,
  });
  const data = query.data?.data;

  return (
    <RequirePermission anyOf={["reports.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Reports" description="Figures for your companies and sales channels. Summaries are refreshed every 10 minutes." />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:items-end">
          <SelectField label="Report" options={(catalogue.data ?? []).map((r) => ({ value: r.key, label: r.title }))} value={report} onChange={(e) => (setReport(e.target.value), setPage(1))} />
          <TextField label="From (created / completed)" type="date" value={filters.from} onChange={(e) => (setFilters({ ...filters, from: e.target.value }), setPage(1))} />
          <TextField label="To" type="date" value={filters.to} onChange={(e) => (setFilters({ ...filters, to: e.target.value }), setPage(1))} />
          {report === "upcoming-due" && <SelectField label="Due within" options={["7", "14", "30", "60"].map((d) => ({ value: d, label: `${d} days` }))} value={filters.days} onChange={(e) => setFilters({ ...filters, days: e.target.value })} />}
        </div>
        {query.isPending ? <LoadingState /> : query.error ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : data && (
          <Section title={data.title} description={`${formatBusinessDate(data.filters.from)} – ${formatBusinessDate(data.filters.to)}${data.generated_at ? ` · calculated ${new Date(data.generated_at).toLocaleTimeString()}` : ""}`}>
            <div className="flex flex-col gap-4">
              {data.summary && (
                <div className="flex flex-wrap gap-2">{Object.entries(data.summary).map(([k, v]) => <Badge key={k} tone="neutral">{headerText(k)}: {v ?? "—"}</Badge>)}</div>
              )}
              {data.total !== undefined && <Badge tone="brand" className="self-start">Total: {data.total}</Badge>}
              {data.totals && data.totals.length > 0 && <RowsTable rows={data.totals} caption="Totals" />}
              {data.by_status && <div className="flex flex-wrap gap-2">{Object.entries(data.by_status).map(([k, v]) => <Badge key={k} tone="neutral">{headerText(k)}: {v}</Badge>)}</div>}
              {data.average_resolution_hours !== undefined && <span className="text-xs text-muted">Average resolution: {data.average_resolution_hours ?? "—"} h</span>}
              <RowsTable rows={data.rows ?? []} caption={data.title} />
              {query.data?.meta && query.data.meta.last_page > 1 && (
                <div className="flex items-center justify-between text-xs text-muted">
                  <span>Page {query.data.meta.page} of {query.data.meta.last_page} · {query.data.meta.total} jobs</span>
                  <div className="flex gap-2">
                    <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
                    <Button size="sm" variant="secondary" disabled={page >= query.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
                  </div>
                </div>
              )}
            </div>
          </Section>
        )}
      </div>
    </RequirePermission>
  );
}
