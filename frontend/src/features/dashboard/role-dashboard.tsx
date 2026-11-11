"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Badge, Section } from "@/components/ui/form-controls";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { api } from "@/lib/api-client";
import { formatBusinessDate } from "@/lib/format";

export interface DashboardMetric {
  key: string;
  label: string;
  value: number | string | null;
  link: string | null;
}

export interface DashboardSection {
  key: string;
  title: string;
  metrics: DashboardMetric[];
  items?: { label: string; due_date: string | null; is_urgent: boolean; status: string; link: string }[];
  jobs_by_status?: { status: string; label: string; total: number }[];
  branches?: { branch: string; page: string; active_jobs: number }[];
}

/** Only in-app paths are linked. */
const inApp = (href: string | null) => (href && href.startsWith("/") && !href.startsWith("//") ? href : null);

export function MetricCard({ metric }: { metric: DashboardMetric }) {
  const href = inApp(metric.link);
  const body = (
    <>
      <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{metric.label}</span>
      <span className="text-2xl font-extrabold tabular-nums text-ink">{metric.value ?? "—"}</span>
    </>
  );
  const cls = "flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-xs";

  return href ? <Link href={href} className={`${cls} transition hover:border-brand/50`}>{body}</Link> : <div className={cls}>{body}</div>;
}

/**
 * Role-specific dashboard: the server decides which sections the user gets (by permission) and
 * limits every figure to the user's scope; the client only renders them. Refreshed every minute.
 */
export function RoleDashboard() {
  const query = useQuery({
    queryKey: ["dashboard"],
    queryFn: ({ signal }) => api.get<{ data: { sections: DashboardSection[]; refresh_seconds: number } }>("dashboard", undefined, signal).then((r) => r.data),
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  });

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;

  return (
    <div className="flex flex-col gap-6">
      {query.data.sections.map((s) => (
        <Section key={s.key} title={s.title}>
          <div className="flex flex-col gap-5">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{s.metrics.map((m) => <MetricCard key={m.key} metric={m} />)}</div>
            {s.jobs_by_status && s.jobs_by_status.length > 0 && (
              <div className="flex flex-wrap gap-2">
                {s.jobs_by_status.map((r) => <Badge key={r.status} tone="neutral">{r.label}: {r.total}</Badge>)}
              </div>
            )}
            {s.branches && s.branches.length > 0 && (
              <div className="overflow-x-auto rounded-xl border border-line">
                <table className="w-full text-xs">
                  <caption className="sr-only">Active jobs by company / shop</caption>
                  <thead className="bg-subtle text-left text-muted"><tr><th className="px-3 py-2">Company / Shop</th><th className="px-3 py-2">Active jobs</th></tr></thead>
                  <tbody className="divide-y divide-line">{s.branches.map((b) => <tr key={`${b.branch}-${b.page}`}><td className="px-3 py-1.5">{b.page}</td><td className="px-3 py-1.5 font-semibold">{b.active_jobs}</td></tr>)}</tbody>
                </table>
              </div>
            )}
            {s.items && s.items.length > 0 && (
              <ul className="flex flex-col gap-1.5 text-xs">
                {s.items.map((i) => (
                  <li key={i.link} className="flex flex-wrap items-center gap-2">
                    <Link href={inApp(i.link) ?? "#"} className="font-mono font-bold text-brand hover:underline">{i.label}</Link>
                    <span className="text-muted">due {formatBusinessDate(i.due_date)}</span>
                    {i.is_urgent && <Badge tone="danger">Urgent</Badge>}
                    <span className="text-muted">· {i.status.replace(/_/g, " ")}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Section>
      ))}
    </div>
  );
}
