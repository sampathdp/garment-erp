"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/form-controls";
import {
  BoxesIcon,
  CalendarIcon,
  CheckCircleIcon,
  ChevronLeftIcon,
  DownloadIcon,
  ExternalLinkIcon,
  FileTextIcon,
  PrinterIcon,
  ScissorsIcon,
  SearchIcon,
  ShirtIcon,
  SparklesIcon,
  XIcon,
} from "@/components/ui/icons";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { buildUrl } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import {
  type JobCardMeasurement,
  bulkStatusTone,
  useBulkGarmentList,
  useJobCard,
} from "./api";

function SpecCard({
  label,
  value,
  subvalue,
  icon,
}: {
  label: string;
  value: React.ReactNode;
  subvalue?: string | null;
  icon?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-4 shadow-xs transition hover:border-brand/30">
      <div className="flex items-center justify-between gap-2 mb-2">
        <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</span>
        {icon && <span className="text-muted/70">{icon}</span>}
      </div>
      <div className="flex flex-col gap-0.5">
        <span className="text-base font-bold text-ink truncate">{value}</span>
        {subvalue && <span className="text-xs text-muted truncate">{subvalue}</span>}
      </div>
    </div>
  );
}

const measure = (m: JobCardMeasurement | null) => {
  if (m === null) return <span className="text-muted font-normal">—</span>;
  if (m.value === null) {
    return <span className="rounded bg-amber-500/10 px-1.5 py-0.5 text-xs font-semibold text-amber-500">Pending</span>;
  }
  return <span className="font-mono font-bold text-ink">{m.value} {m.unit}</span>;
};

const measureText = (m: JobCardMeasurement | null) => {
  if (m === null || m.value === null) return "—";
  return `${m.value} ${m.unit}`;
};

/**
 * Modern, High-Contrast Factory Job Card.
 * Includes interactive web screen AND pixel-perfect physical print traveler.
 */
export function JobCardView({ id }: { id: number }) {
  const query = useJobCard(id);
  const rosterQuery = useBulkGarmentList(id, true);
  const [search, setSearch] = useState("");
  const [selectedSize, setSelectedSize] = useState<string>("all");
  const [viewMode, setViewMode] = useState<"both" | "roster" | "matrix">("both");

  const c = query.data;

  // Filter roster items for screen view
  const rosterItems = rosterQuery.data?.items;
  const filteredItems = useMemo(() => {
    if (!rosterItems) return [];
    const term = search.trim().toLowerCase();
    return rosterItems.filter((item) => {
      const matchSearch =
        !term ||
        [item.player_name, item.number, item.size, item.notes, ...Object.values(item.values)].some(
          (val) => (val ?? "").toLowerCase().includes(term),
        );
      const matchSize = selectedSize === "all" || item.size === selectedSize;
      return matchSearch && matchSize;
    });
  }, [rosterItems, search, selectedSize]);

  if (query.isPending) return <LoadingState label="Loading production job card…" />;
  if (query.error || !c) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;

  const isUrgent = c.is_urgent;
  const roster = rosterQuery.data;
  const allItems = roster?.items ?? [];

  const bulkM = parseFloat(c.bulk_meter?.value ?? "0") || 0;
  const patchM = parseFloat(c.patch_meter?.value ?? "0") || 0;
  const totalM = bulkM + patchM;
  const avgM = totalM > 0 && c.quantity > 0 ? totalM / c.quantity : 0;

  return (
    <div>
      {/* =========================================================================
          SCREEN VIEW (Modern Interactive ERP Dashboard) - Hidden on Print
          ========================================================================= */}
      <div className="flex flex-col gap-6 print:hidden">
        {/* Top Executive Header */}
        <div className="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6 shadow-xs">
          {/* Breadcrumb + Metadata */}
          <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
            <div className="flex items-center gap-2">
              <Link href="/bulk-jobs/" className="hover:text-ink font-medium">
                Bulk Production
              </Link>
              <span>/</span>
              <Link href={`/bulk-jobs/view/?id=${id}`} className="hover:text-ink font-mono font-semibold">
                Job {c.job_no}
              </Link>
              <span>/</span>
              <span className="font-semibold text-ink">Production Job Card</span>
            </div>

            <div className="flex items-center gap-2">
              <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
                Channel: {c.page}
              </span>
              <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
                Client: {c.customer}
              </span>
            </div>
          </div>

          {/* Title, Badges & Quick Action Bar */}
          <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-line pb-5">
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-extrabold tracking-tight text-ink">
                  Production Job Card
                </h1>

                <span className="rounded-lg bg-brand/10 border border-brand/20 px-2.5 py-1 font-mono text-sm font-bold text-brand">
                  {c.job_no}
                </span>

                {isUrgent && (
                  <Badge tone="danger" dot>
                    ⚡ URGENT PRIORITY
                  </Badge>
                )}

                <Badge tone={bulkStatusTone(c.bulk.status.value)} dot>
                  {c.bulk.status.label}
                </Badge>

                <Badge tone="neutral">
                  {c.bulk.mode === "internal" ? "Workshop: Internal" : "Workshop: External"}
                </Badge>
              </div>

              <p className="text-xs text-muted">
                Official factory traveler card for Cutting, Sublimation Printing, and Sewing departments.
              </p>
            </div>

            {/* Action Buttons */}
            <div className="flex flex-wrap items-center gap-2">
              <Link
                href={`/bulk-jobs/view/?id=${id}`}
                className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 text-xs font-semibold text-ink shadow-xs hover:bg-subtle transition"
              >
                <ChevronLeftIcon size={14} />
                <span>Back to Job</span>
              </Link>

              <Link
                href={`/orders/view/?id=${c.order_id}`}
                className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 text-xs font-semibold text-ink shadow-xs hover:bg-subtle transition"
              >
                <ExternalLinkIcon size={13} />
                <span>View Order</span>
              </Link>

              <a
                href={buildUrl(`bulk-jobs/${id}/garment-list/export`)}
                className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 text-xs font-semibold text-ink shadow-xs hover:bg-subtle transition"
              >
                <DownloadIcon size={14} />
                <span>Export CSV</span>
              </a>

              <Button
                size="sm"
                icon={<PrinterIcon size={14} />}
                onClick={() => window.print()}
                className="shadow-xs"
              >
                Print Card
              </Button>
            </div>
          </div>

          {/* Production KPI Grid */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <SpecCard
              label="Total Quantity"
              value={`${c.quantity} pcs`}
              subvalue="Allocated garment pieces"
              icon={<ShirtIcon size={18} className="text-brand" />}
            />
            <SpecCard
              label="Product Category"
              value={c.product.category}
              subvalue={c.product.order_type ?? "Bulk Custom Order"}
              icon={<BoxesIcon size={18} className="text-amber-500" />}
            />
            <SpecCard
              label="Material & GSM"
              value={c.material ?? "Standard Fabric"}
              subvalue={c.material_details ?? "Production Grade Fabric"}
              icon={<ScissorsIcon size={18} className="text-emerald-500" />}
            />
            <SpecCard
              label="Delivery Deadline"
              value={formatBusinessDate(c.due_date)}
              subvalue="Final islandwide handover"
              icon={<CalendarIcon size={18} className="text-purple-500" />}
            />
          </div>
        </div>

        {/* Main Grid: Artwork Preview + Technical Cutting Specs */}
        <div className="grid gap-6 lg:grid-cols-12">
          {/* Left: Approved Artwork Showcase */}
          <div className="lg:col-span-5 flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5 shadow-xs">
            <div className="flex items-center justify-between border-b border-line pb-3">
              <div className="flex items-center gap-2">
                <CheckCircleIcon size={16} className="text-emerald-500" />
                <h3 className="text-xs font-bold uppercase tracking-wider text-ink">
                  Approved Production Artwork
                </h3>
              </div>
              <span className="text-[11px] font-semibold text-muted">Proof Approved</span>
            </div>

            <div className="relative flex flex-col items-center justify-center overflow-hidden rounded-xl border border-line bg-subtle/40 p-3 min-h-[200px]">
              {c.design_image_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- signed URL
                <img
                  src={c.design_image_url}
                  alt={`Approved design of ${c.job_no}`}
                  className="max-h-64 w-auto rounded-lg object-contain shadow-xs transition-transform duration-200 hover:scale-[1.02]"
                />
              ) : (
                <div className="flex flex-col items-center gap-2 py-12 text-center text-xs text-muted">
                  <ShirtIcon size={32} className="text-muted/60" />
                  <p>No preview graphic available.</p>
                </div>
              )}
            </div>

            {c.final_design_link && (
              <a
                href={c.final_design_link}
                target="_blank"
                rel="noopener noreferrer nofollow"
                className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-brand/30 bg-brand/10 px-4 text-xs font-bold text-brand transition hover:bg-brand hover:text-white shadow-2xs"
              >
                <ExternalLinkIcon size={14} />
                <span>Open High-Res Production Files (Cloud Link)</span>
              </a>
            )}
          </div>

          {/* Right: Technical Cutting & Fabric Measurements */}
          <div className="lg:col-span-7 flex flex-col justify-between rounded-2xl border border-line bg-surface p-5 shadow-xs">
            <div>
              <div className="flex items-center justify-between border-b border-line pb-3 mb-4">
                <div className="flex items-center gap-2">
                  <ScissorsIcon size={16} className="text-brand" />
                  <h3 className="text-xs font-bold uppercase tracking-wider text-ink">
                    Cutting & Material Specifications
                  </h3>
                </div>
                <span className="text-[11px] font-semibold text-muted">Workshop Directives</span>
              </div>

              <dl className="grid gap-3 sm:grid-cols-2 text-xs">
                <div className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Job Number</dt>
                  <dd className="font-mono text-sm font-extrabold text-ink">{c.job_no}</dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Production Workshop</dt>
                  <dd className="text-sm font-bold text-ink">
                    {c.bulk.mode === "internal" ? "Internal Workshop" : "External Producer"}
                    {c.bulk.approved_version_no ? ` (v${c.bulk.approved_version_no} approved)` : ""}
                  </dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Fabric / Material</dt>
                  <dd className="font-bold text-ink">
                    {c.material ?? "—"} {c.material_details ? `· ${c.material_details}` : ""}
                  </dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Total Order Quantity</dt>
                  <dd className="font-bold text-ink">{c.quantity} pieces</dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Bulk Meter Measurement</dt>
                  <dd className="text-sm">{measure(c.bulk_meter)}</dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Patch Meter Measurement</dt>
                  <dd className="text-sm">{measure(c.patch_meter)}</dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-brand-soft/20 p-3.5 border border-brand/20">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-brand">Total Fabric Yardage</dt>
                  <dd className="font-mono text-sm font-black text-brand">
                    {totalM > 0 ? `${totalM.toFixed(2)} m` : "—"}
                  </dd>
                </div>

                <div className="flex flex-col gap-1 rounded-xl bg-emerald-500/10 p-3.5 border border-emerald-500/20">
                  <dt className="text-[11px] font-bold uppercase tracking-wider text-emerald-600">Average Consumption (Yield)</dt>
                  <dd className="font-mono text-sm font-extrabold text-emerald-600">
                    {avgM > 0 ? `${avgM.toFixed(3)} m / garment` : "—"}
                  </dd>
                </div>

                {c.other_measurements.map((m) => (
                  <div key={m.key} className="flex flex-col gap-1 rounded-xl bg-subtle/40 p-3.5 border border-line/50">
                    <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{m.label}</dt>
                    <dd className="text-sm">{measure(m)}</dd>
                  </div>
                ))}
              </dl>
            </div>

            <p className="mt-4 text-[11px] text-muted border-t border-line/60 pt-3">
              Measurements and yardage calculations are verified by the assigned Bulk Designer prior to pattern cutting.
            </p>
          </div>
        </div>

        {/* Sizing Distribution Breakdown & Roster Section */}
        {c.list && (
          <div className="rounded-2xl border border-line bg-surface p-6 shadow-xs flex flex-col gap-5">
            {/* Section Header with View Toggles */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-line pb-4">
              <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="text-base font-extrabold text-ink">
                    Confirmed Size Matrix & Player Roster
                  </h3>
                  <span className="rounded-full bg-brand/10 border border-brand/20 px-2 py-0.5 text-xs font-bold text-brand">
                    v{c.list.version_no}
                  </span>
                  {c.list.is_approved_reference && (
                    <Badge tone="success" dot>Approved Reference</Badge>
                  )}
                  {!c.list.is_current && (
                    <Badge tone="danger" dot>Correction in Progress</Badge>
                  )}
                </div>
                <p className="text-xs text-muted">
                  Locked on {formatDateTime(c.list.locked_at)} · Total: {c.list.total_quantity} garments
                </p>
              </div>

              {/* View Mode Switcher */}
              <div className="flex items-center gap-1.5 rounded-xl border border-line bg-subtle/50 p-1">
                <button
                  type="button"
                  onClick={() => setViewMode("both")}
                  className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                    viewMode === "both" ? "bg-surface text-ink shadow-2xs" : "text-muted hover:text-ink"
                  }`}
                >
                  All Views
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("matrix")}
                  className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                    viewMode === "matrix" ? "bg-surface text-ink shadow-2xs" : "text-muted hover:text-ink"
                  }`}
                >
                  Size Matrix
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("roster")}
                  className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                    viewMode === "roster" ? "bg-surface text-ink shadow-2xs" : "text-muted hover:text-ink"
                  }`}
                >
                  Player Roster ({filteredItems.length})
                </button>
              </div>
            </div>

            {/* Part A: Visual Size Matrix Pills & Distribution */}
            {(viewMode === "both" || viewMode === "matrix") && (
              <div className="grid gap-4 md:grid-cols-2 rounded-xl border border-line bg-subtle/20 p-4">
                {/* Size Distribution Cards */}
                <div className="flex flex-col gap-2.5">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                    Size Breakdown ({c.list.by_size.length} Sizes)
                  </span>
                  <div className="flex flex-wrap gap-2">
                    {c.list.by_size.map((s) => {
                      const active = selectedSize === s.size;
                      return (
                        <button
                          key={s.size}
                          type="button"
                          onClick={() => setSelectedSize(active ? "all" : s.size)}
                          className={`inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-bold transition cursor-pointer ${
                            active
                              ? "border-brand bg-brand text-white shadow-xs"
                              : "border-line bg-surface text-ink hover:border-brand/40 hover:bg-subtle"
                          }`}
                        >
                          <span>{s.size}:</span>
                          <span className={`rounded-full px-1.5 py-0.2 text-[11px] font-extrabold ${active ? "bg-white/20 text-white" : "bg-brand/10 text-brand"}`}>
                            {s.quantity} pcs
                          </span>
                        </button>
                      );
                    })}
                  </div>
                  {selectedSize !== "all" && (
                    <button
                      type="button"
                      onClick={() => setSelectedSize("all")}
                      className="text-xs text-brand hover:underline self-start mt-1"
                    >
                      Reset size filter
                    </button>
                  )}
                </div>

                {/* Garment Cut & Classification */}
                <div className="flex flex-col gap-2.5">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                    Pattern / Specification Groups
                  </span>
                  <div className="flex flex-col gap-2">
                    {c.list.groups.map((g) => (
                      <div
                        key={g.label}
                        className="flex items-center justify-between rounded-xl border border-line bg-surface p-2.5 text-xs"
                      >
                        <span className="font-semibold text-ink">{g.label}</span>
                        <span className="rounded-lg bg-subtle font-mono font-bold text-ink px-2.5 py-0.5">
                          {g.quantity} pcs
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            )}

            {/* Part B: Interactive Confirmed Player Roster Table */}
            {(viewMode === "both" || viewMode === "roster") && (
              <div className="flex flex-col gap-3">
                {/* Search Toolbar */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                  <div className="w-full sm:max-w-md relative">
                    <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                      <SearchIcon size={14} />
                    </span>
                    <input
                      type="text"
                      placeholder="Search player name, squad number, size, or notes…"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      maxLength={80}
                      className="w-full rounded-xl border border-line bg-surface py-2 pl-9 pr-8 text-xs text-ink placeholder:text-muted focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand shadow-xs"
                    />
                    {search && (
                      <button
                        type="button"
                        onClick={() => setSearch("")}
                        className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted hover:text-ink cursor-pointer"
                      >
                        <XIcon size={14} />
                      </button>
                    )}
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted">
                      Showing {filteredItems.length} of {roster?.items.length ?? c.list.total_quantity} players
                    </span>
                  </div>
                </div>

                {/* Player Roster Table */}
                <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
                  <table className="w-full min-w-[34rem] text-left text-xs">
                    <thead className="border-b border-line bg-subtle/50 text-[11px] font-bold uppercase tracking-wider text-muted">
                      <tr>
                        <th className="px-3.5 py-3">#</th>
                        <th className="px-3.5 py-3">Size</th>
                        <th className="px-3.5 py-3">Qty</th>
                        <th className="px-3.5 py-3">Player / Individual Name</th>
                        <th className="px-3.5 py-3">Jersey #</th>
                        {roster?.schema.fields
                          .filter((f) => f.key !== "player_name" && f.key !== "number")
                          .map((f) => (
                            <th key={f.key} className="px-3.5 py-3">{f.label}</th>
                          ))}
                        <th className="px-3.5 py-3">Custom Instructions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line/70">
                      {filteredItems.length === 0 ? (
                        <tr>
                          <td colSpan={7} className="p-8 text-center text-xs text-muted">
                            {search || selectedSize !== "all"
                              ? "No matching players found. Try adjusting your filters."
                              : "No individual roster rows found."}
                          </td>
                        </tr>
                      ) : (
                        filteredItems.map((r) => (
                          <tr key={r.line_no} className="hover:bg-subtle/30 transition">
                            <td className="px-3.5 py-2.5 font-mono text-muted text-xs font-semibold">
                              {r.line_no}
                            </td>
                            <td className="px-3.5 py-2.5">
                              <span className="rounded-md bg-brand/10 border border-brand/20 px-2 py-0.5 text-xs font-bold text-brand">
                                {r.size ?? "—"}
                              </span>
                            </td>
                            <td className="px-3.5 py-2.5 font-bold text-ink">{r.quantity}</td>
                            <td className="px-3.5 py-2.5 font-extrabold text-ink tracking-wide">
                              {r.player_name || <span className="text-muted font-normal italic">—</span>}
                            </td>
                            <td className="px-3.5 py-2.5">
                              {r.number ? (
                                <span className="font-mono text-xs font-black text-ink bg-subtle px-2 py-0.5 rounded border border-line">
                                  #{r.number}
                                </span>
                              ) : (
                                <span className="text-muted italic">—</span>
                              )}
                            </td>
                            {roster?.schema.fields
                              .filter((f) => f.key !== "player_name" && f.key !== "number")
                              .map((f) => {
                                const val = r.values[f.key] ?? "";
                                const displayVal = f.options.find((o) => o.code === val)?.label ?? val;
                                return (
                                  <td key={f.key} className="px-3.5 py-2.5 text-ink font-medium">
                                    {displayVal || <span className="text-muted">—</span>}
                                  </td>
                                );
                              })}
                            <td className="px-3.5 py-2.5 text-muted text-xs">
                              {r.notes || <span className="text-muted/60">—</span>}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        )}
      </div>

      {/* =========================================================================
          PHYSICAL PRINTABLE JOB TRAVELER (High-Contrast, Crisp Paper Layout)
          Hidden on screen, automatically activated when printing (Ctrl+P / Print Button)
          ========================================================================= */}
      <div className="hidden print:block text-black bg-white text-[11px] leading-tight font-sans">
        {/* Print Header */}
        <div className="border-b-2 border-black pb-3 mb-3">
          <div className="flex items-start justify-between">
            <div>
              <h1 className="text-xl font-black uppercase tracking-wider text-black m-0">
                FACTORY PRODUCTION JOB CARD
              </h1>
              <p className="text-[10px] text-gray-700 mt-0.5 font-medium">
                Official Cutting, Sublimation Printing & Assembly Traveler
              </p>
            </div>

            <div className="text-right flex flex-col items-end">
              <span className="font-mono text-lg font-black tracking-tight border-2 border-black px-2.5 py-0.5 bg-gray-100">
                {c.job_no}
              </span>
              {isUrgent && (
                <span className="mt-1 text-[10px] font-black uppercase text-red-700 border border-red-700 px-1.5 py-0.2">
                  *** URGENT PRIORITY ***
                </span>
              )}
            </div>
          </div>

          {/* Quick Meta Strip */}
          <div className="grid grid-cols-4 gap-2 mt-2 pt-2 border-t border-gray-300 text-[10px]">
            <div>
              <span className="text-gray-600 block uppercase font-bold text-[9px]">Customer</span>
              <strong className="text-black text-[11px]">{c.customer}</strong>
            </div>
            <div>
              <span className="text-gray-600 block uppercase font-bold text-[9px]">Sales Brand / Page</span>
              <strong className="text-black text-[11px]">{c.page}</strong>
            </div>
            <div>
              <span className="text-gray-600 block uppercase font-bold text-[9px]">Delivery Due Date</span>
              <strong className="text-black text-[11px]">{formatBusinessDate(c.due_date)}</strong>
            </div>
            <div>
              <span className="text-gray-600 block uppercase font-bold text-[9px]">Total Pieces</span>
              <strong className="text-black text-[12px]">{c.quantity} pcs</strong>
            </div>
          </div>
        </div>

        {/* Section 1: Artwork & Specifications Side-by-Side */}
        <div className="grid grid-cols-12 gap-3 mb-3 pb-3 border-b border-gray-300">
          {/* Artwork Box */}
          <div className="col-span-5 flex flex-col items-center justify-center border border-gray-400 p-2 min-h-[190px] bg-gray-50">
            {c.design_image_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={c.design_image_url}
                alt="Approved Artwork"
                className="max-h-[175px] max-w-full object-contain"
              />
            ) : (
              <div className="text-center text-gray-500 py-8">
                <p className="font-bold">No Artwork Preview</p>
                <p className="text-[9px]">Refer to design file repository</p>
              </div>
            )}
            <span className="text-[9px] font-bold text-gray-700 mt-1 uppercase">
              Approved Production Artwork
            </span>
          </div>

          {/* Technical Specs Table */}
          <div className="col-span-7">
            <table className="w-full border-collapse border border-gray-400 text-[10px]">
              <tbody>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300 w-1/3">
                    Product Model
                  </th>
                  <td className="p-1.5 font-semibold">
                    {c.product.category} {c.product.order_type ? `(${c.product.order_type})` : ""}
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Fabric / Material
                  </th>
                  <td className="p-1.5 font-semibold">
                    {c.material ?? "—"} {c.material_details ? `· ${c.material_details}` : ""}
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Total Quantity
                  </th>
                  <td className="p-1.5 font-black text-black">
                    {c.quantity} garments
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Bulk Meter Yardage
                  </th>
                  <td className="p-1.5 font-mono font-bold">
                    {measureText(c.bulk_meter)}
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Patch Meter Yardage
                  </th>
                  <td className="p-1.5 font-mono font-bold">
                    {measureText(c.patch_meter)}
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Total Fabric Yardage
                  </th>
                  <td className="p-1.5 font-mono font-black text-black">
                    {totalM > 0 ? `${totalM.toFixed(2)} m` : "—"}
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Average Yield / Piece
                  </th>
                  <td className="p-1.5 font-mono font-bold text-black">
                    {avgM > 0 ? `${avgM.toFixed(3)} m / garment` : "—"}
                  </td>
                </tr>
                <tr className="border-b border-gray-300">
                  <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                    Production Workshop
                  </th>
                  <td className="p-1.5 font-semibold">
                    {c.bulk.mode === "internal" ? "Internal Workshop" : "External Producer"}
                  </td>
                </tr>
                {c.final_design_link && (
                  <tr>
                    <th className="bg-gray-100 font-bold p-1.5 text-left border-r border-gray-300">
                      High-Res Files Link
                    </th>
                    <td className="p-1.5 font-mono text-[9px] text-gray-800 break-all truncate">
                      {c.final_design_link}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Section 2: Size Breakdown Matrix */}
        {c.list && (
          <div className="mb-3 pb-3 border-b border-gray-300">
            <h2 className="text-[11px] font-black uppercase tracking-wider text-black mb-1.5">
              Production Size Distribution (v{c.list.version_no} · {c.list.total_quantity} pieces)
            </h2>
            <div className="flex border border-black divide-x divide-black text-center text-[10px]">
              {c.list.by_size.map((s) => (
                <div key={s.size} className="flex-1 p-1">
                  <span className="block font-bold text-gray-700 text-[9px] uppercase">{s.size}</span>
                  <span className="block font-black text-[12px]">{s.quantity}</span>
                </div>
              ))}
              <div className="flex-1 p-1 bg-gray-100 font-black">
                <span className="block text-gray-700 text-[9px] uppercase">TOTAL</span>
                <span className="block text-[12px]">{c.list.total_quantity}</span>
              </div>
            </div>
          </div>
        )}

        {/* Section 3: Full Confirmed Player Roster Table */}
        <div className="mb-4">
          <h2 className="text-[11px] font-black uppercase tracking-wider text-black mb-1.5">
            Confirmed Player Roster & Custom Specifications ({allItems.length} Line Items)
          </h2>
          <table className="w-full border-collapse border border-black text-[10px]">
            <thead>
              <tr className="bg-gray-200 border-b border-black font-black uppercase text-[9px]">
                <th className="border-r border-black p-1 text-center w-8">#</th>
                <th className="border-r border-black p-1 text-center w-12">Size</th>
                <th className="border-r border-black p-1 text-center w-10">Qty</th>
                <th className="border-r border-black p-1 text-left">Player / Individual Name</th>
                <th className="border-r border-black p-1 text-center w-16">Jersey #</th>
                <th className="border-r border-black p-1 text-left w-24">Pattern / Sleeve</th>
                <th className="p-1 text-left">Instructions / Custom Notes</th>
              </tr>
            </thead>
            <tbody>
              {allItems.map((item, idx) => (
                <tr key={item.line_no} className={`border-b border-gray-300 ${idx % 2 === 1 ? "bg-gray-50" : ""}`}>
                  <td className="border-r border-black p-1 text-center font-mono font-bold">
                    {item.line_no}
                  </td>
                  <td className="border-r border-black p-1 text-center font-black">
                    {item.size ?? "—"}
                  </td>
                  <td className="border-r border-black p-1 text-center font-bold">
                    {item.quantity}
                  </td>
                  <td className="border-r border-black p-1 font-bold uppercase tracking-wider">
                    {item.player_name || <span className="text-gray-400 font-normal">—</span>}
                  </td>
                  <td className="border-r border-black p-1 text-center font-mono font-black">
                    {item.number ? `#${item.number}` : "—"}
                  </td>
                  <td className="border-r border-black p-1 text-gray-800 capitalize">
                    {item.values["sleeve_type"] ?? "Standard"}
                  </td>
                  <td className="p-1 text-gray-700">
                    {item.notes || "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Section 4: Production Department Sign-Off & Verification Strip */}
        <div className="border border-black p-2 mt-3 bg-gray-50 break-inside-avoid">
          <span className="block font-black text-[9px] uppercase tracking-wider text-black mb-2 text-center border-b border-gray-300 pb-1">
            Factory Floor Department Approvals & Traveler Sign-Off
          </span>
          <div className="grid grid-cols-4 gap-2 text-[9px]">
            <div className="border border-gray-400 p-2 bg-white">
              <span className="font-bold block text-black">1. Pattern / Bulk Designer</span>
              <p className="mt-3 text-gray-500">Sign: ___________________</p>
              <p className="mt-1 text-gray-500">Date: ___________________</p>
            </div>
            <div className="border border-gray-400 p-2 bg-white">
              <span className="font-bold block text-black">2. Fabric Cutting</span>
              <p className="mt-3 text-gray-500">Sign: ___________________</p>
              <p className="mt-1 text-gray-500">Date: ___________________</p>
            </div>
            <div className="border border-gray-400 p-2 bg-white">
              <span className="font-bold block text-black">3. Sublimation / Printing</span>
              <p className="mt-3 text-gray-500">Sign: ___________________</p>
              <p className="mt-1 text-gray-500">Date: ___________________</p>
            </div>
            <div className="border border-gray-400 p-2 bg-white">
              <span className="font-bold block text-black">4. Final QC & Packing</span>
              <p className="mt-3 text-gray-500">Sign: ___________________</p>
              <p className="mt-1 text-gray-500">Date: ___________________</p>
            </div>
          </div>
        </div>

        {/* Print Footer */}
        <div className="flex justify-between items-center text-[8px] text-gray-500 mt-3 pt-1 border-t border-gray-300">
          <span>Antigravity Garment ERP · Traveler Card {c.job_no}</span>
          <span>Printed on {formatDateTime(new Date().toISOString())}</span>
          <span>Page 1 of 1</span>
        </div>
      </div>
    </div>
  );
}
