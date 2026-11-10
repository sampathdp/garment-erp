"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Badge, PageHeader } from "@/components/ui/form-controls";
import { ExternalLinkIcon } from "@/components/ui/icons";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import { type JobCardMeasurement, bulkStatusTone, useJobCard } from "./api";

function Value({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-line/60 py-2">
      <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-ink">{children}</dd>
    </div>
  );
}

const measure = (m: JobCardMeasurement | null) => (m === null ? "—" : m.value === null ? <span className="text-danger">Not entered</span> : `${m.value} ${m.unit}`);

/**
 * The Job Card. Every value comes from the API's live read model (order, approved design,
 * measurements, confirmed list): nothing is typed in twice, so the card is always current.
 */
export function JobCardView({ id }: { id: number }) {
  const query = useJobCard(id);
  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const c = query.data;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Job Card — ${c.job_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted print:hidden">
            <Link href={`/bulk-jobs/view/?id=${id}`} className="hover:text-ink">Bulk job</Link>
            <span>/</span>
            <span className="font-semibold text-ink">Job Card</span>
          </div>
        }
        description={`${c.customer} · ${c.branch} / ${c.page} · Due ${formatBusinessDate(c.due_date)}`}
        actions={
          <div className="flex items-center gap-2 print:hidden">
            <Badge tone={bulkStatusTone(c.bulk.status.value)} dot>{c.bulk.status.label}</Badge>
            <Button size="sm" variant="secondary" onClick={() => window.print()}>Print</Button>
          </div>
        }
      />
      <div className="grid gap-6 rounded-xl border border-line bg-surface p-5 shadow-xs md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <div className="flex flex-col gap-3">
          {c.design_image_url ? (
            // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
            <img src={c.design_image_url} alt={`Approved design of ${c.job_no}`} className="w-full rounded-lg border border-line object-contain" />
          ) : (
            <div className="flex h-48 items-center justify-center rounded-lg border border-dashed border-line text-xs text-muted">No design preview</div>
          )}
          {c.final_design_link && (
            <a className="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline" href={c.final_design_link} target="_blank" rel="noopener noreferrer nofollow">
              Final design link <ExternalLinkIcon size={12} />
            </a>
          )}
        </div>
        <dl className="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
          <Value label="Job number">{c.job_no}{c.is_urgent ? " · URGENT" : ""}</Value>
          <Value label="Product">{c.product.category}{c.product.order_type ? ` · ${c.product.order_type}` : ""}</Value>
          <Value label="Material">{c.material ?? "—"}{c.material_details ? ` · ${c.material_details}` : ""}</Value>
          <Value label="Quantity">{c.quantity} pcs</Value>
          <Value label="Bulk meter">{measure(c.bulk_meter)}</Value>
          <Value label="Patch meter">{measure(c.patch_meter)}</Value>
          {c.other_measurements.map((m) => (
            <Value key={m.key} label={m.label}>{measure(m)}</Value>
          ))}
          <Value label="Production">{c.bulk.mode === null ? "Not assigned" : c.bulk.mode === "internal" ? "Internal" : "External"}{c.bulk.approved_version_no ? ` · bulk v${c.bulk.approved_version_no} approved` : ""}</Value>
        </dl>
      </div>
      {c.list && (
        <div className="rounded-xl border border-line bg-surface p-5 shadow-xs">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <p className="text-sm font-semibold text-ink">Confirmed name/number list — version {c.list.version_no}</p>
            {c.list.is_approved_reference && <Badge tone="success">Approved reference</Badge>}
            {!c.list.is_current && <Badge tone="danger">List is being corrected</Badge>}
            <span className="text-xs text-muted">Locked {formatDateTime(c.list.locked_at)} · {c.list.total_quantity} pcs</span>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <table className="text-xs">
              <caption className="mb-1 text-left font-semibold text-muted">By size</caption>
              <tbody className="divide-y divide-line">
                {c.list.by_size.map((s) => (
                  <tr key={s.size}><td className="py-1 pr-4">{s.size}</td><td className="py-1 font-semibold">{s.quantity}</td></tr>
                ))}
              </tbody>
            </table>
            <table className="text-xs">
              <caption className="mb-1 text-left font-semibold text-muted">By type</caption>
              <tbody className="divide-y divide-line">
                {c.list.groups.map((g) => (
                  <tr key={g.label}><td className="py-1 pr-4">{g.label}</td><td className="py-1 font-semibold">{g.quantity}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
