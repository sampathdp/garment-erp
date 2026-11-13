"use client";

import Link from "next/link";
import { useDeferredValue, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import {
  Badge,
  Checkbox,
  FormError,
  PageHeader,
  Section,
  SelectField,
  TextAreaField,
} from "@/components/ui/form-controls";
import {
  AlertTriangleIcon,
  BoxesIcon,
  CheckCircleIcon,
  CheckIcon,
  ChevronRightIcon,
  DownloadIcon,
  ExternalLinkIcon,
  FileTextIcon,
  PencilIcon,
  PlusIcon,
  ScissorsIcon,
  SearchIcon,
  ShirtIcon,
  SparklesIcon,
  TagIcon,
  XIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { formatDateTime, formatMoney } from "@/lib/format";
import {
  exportUrl,
  type GarmentField,
  type GarmentList,
  type GarmentRow,
  parseBulkRows,
  type RowInput,
  useGarmentList,
  useGarmentMutations,
} from "./api";

const errorOf = (e: unknown) =>
  e instanceof ApiError
    ? [e.message, ...Object.values(e.fieldErrors).flat()].slice(0, 6).join(" ")
    : null;

function valueOf(row: GarmentRow, f: GarmentField): string {
  if (f.key === "player_name") return row.player_name ?? "";
  if (f.key === "number") return row.number ?? "";
  return row.values[f.key] ?? "";
}

function display(row: GarmentRow, f: GarmentField): string {
  const v = valueOf(row, f);
  if (f.input_type === "select") return f.options.find((o) => o.code === v)?.label ?? v;
  if (f.input_type === "boolean") return v === "" ? "" : v === "yes" ? "Yes" : "No";
  return v;
}

function RowEditor({
  list,
  row,
  onSave,
  onCancel,
  pending,
  error,
}: {
  list: GarmentList;
  row: Partial<GarmentRow> | null;
  onSave: (r: RowInput, andAddAnother?: boolean) => void;
  onCancel: () => void;
  pending: boolean;
  error: unknown;
}) {
  const fields = list.schema.fields;
  const [v, setV] = useState<Record<string, string>>(() => {
    const base: Record<string, string> = {
      size_id: String(row?.size_id ?? ""),
      quantity: String(row?.quantity ?? 1),
      notes: row?.notes ?? "",
      player_name: row?.player_name ?? "",
      number: row?.number ?? "",
    };
    fields.forEach((f) => {
      if (f.key !== "player_name" && f.key !== "number") {
        base[f.key] = row ? valueOf(row as GarmentRow, f) : "";
      }
    });
    return base;
  });

  const set = (k: string, val: string) => setV((p) => ({ ...p, [k]: val }));

  const sizeOptions = useMemo(() => [
    { value: "", label: "Select size…" },
    ...list.schema.sizes.map((s) => ({
      value: String(s.id),
      label: s.label.includes("Kids") || s.label.includes("Adult")
        ? s.label
        : /^\d+$/.test(s.code)
          ? `${s.code} (Kids)`
          : `${s.label} (Adult)`,
    })),
  ], [list.schema.sizes]);

  const save = (andAddAnother = false) => {
    const values: Record<string, string> = {};
    fields
      .filter((f) => f.key !== "player_name" && f.key !== "number" && v[f.key] !== "")
      .forEach((f) => (values[f.key] = v[f.key]));
    onSave(
      {
        size_id: v.size_id ? Number(v.size_id) : null,
        quantity: Number(v.quantity) || 1,
        player_name: v.player_name ? v.player_name.trim() : null,
        number: v.number ? v.number.trim() : null,
        notes: v.notes ? v.notes.trim() : null,
        values,
      },
      andAddAnother,
    );
    if (andAddAnother) {
      setV((prev) => ({
        ...prev,
        player_name: "",
        number: "",
        notes: "",
        quantity: "1",
      }));
    }
  };

  const hasNameOrNum = fields.some((f) => f.key === "player_name" || f.key === "number");
  const otherFields = fields.filter((f) => f.key !== "player_name" && f.key !== "number");

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorOf(error)} />

      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField
          label="Garment Size *"
          options={sizeOptions}
          value={v.size_id}
          onChange={(e) => set("size_id", e.target.value)}
        />

        <TextField
          label="Quantity *"
          inputMode="numeric"
          value={v.quantity}
          onChange={(e) => set("quantity", e.target.value)}
        />
      </div>

      {/* Name and Number fields */}
      {hasNameOrNum && (
        <div className="grid gap-3 sm:grid-cols-2 rounded-xl border border-line bg-subtle/30 p-3">
          {fields.some((f) => f.key === "player_name") && (
            <TextField
              label="Player / Individual Name"
              placeholder="e.g. D. SILVA"
              maxLength={40}
              value={v.player_name}
              onChange={(e) => set("player_name", e.target.value.toUpperCase())}
            />
          )}

          {fields.some((f) => f.key === "number") && (
            <TextField
              label="Player Number"
              placeholder="e.g. 07"
              maxLength={4}
              value={v.number}
              onChange={(e) => set("number", e.target.value)}
            />
          )}
        </div>
      )}

      {/* Custom Fields */}
      {otherFields.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2">
          {otherFields.map((f) =>
            f.input_type === "select" || f.input_type === "boolean" ? (
              <SelectField
                key={f.key}
                label={`${f.label}${f.is_required ? " *" : ""}`}
                options={[
                  { value: "", label: "—" },
                  ...(f.input_type === "boolean"
                    ? [
                        { value: "yes", label: "Yes" },
                        { value: "no", label: "No" },
                      ]
                    : f.options.map((o) => ({ value: o.code, label: o.label }))),
                ]}
                value={v[f.key]}
                onChange={(e) => set(f.key, e.target.value)}
              />
            ) : (
              <TextField
                key={f.key}
                label={`${f.label}${f.is_required ? " *" : ""}`}
                inputMode={f.input_type === "number" ? "numeric" : undefined}
                maxLength={f.max_length ?? 120}
                value={v[f.key]}
                onChange={(e) => set(f.key, e.target.value)}
              />
            ),
          )}
        </div>
      )}

      <TextField
        label="Item Notes / Custom Instructions"
        placeholder="e.g. Long sleeves, captain armband patch"
        maxLength={255}
        value={v.notes}
        onChange={(e) => set("notes", e.target.value)}
      />

      <div className="flex flex-wrap items-center justify-between gap-2.5 pt-3 border-t border-line">
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
        <div className="flex items-center gap-2">
          {!row?.id && (
            <Button
              variant="secondary"
              loading={pending}
              disabled={!v.size_id}
              onClick={() => save(true)}
            >
              Save & Add Another
            </Button>
          )}
          <Button loading={pending} disabled={!v.size_id} onClick={() => save(false)}>
            {row?.id ? "Update item" : "Save item"}
          </Button>
        </div>
      </div>
    </div>
  );
}

function ListEditor({ orderId }: { orderId: number }) {
  const query = useGarmentList(orderId);
  const m = useGarmentMutations(orderId);
  const { notify } = useToast();

  const [search, setSearch] = useState("");
  const [sizeFilter, setSizeFilter] = useState<string>("all");
  const [editing, setEditing] = useState<GarmentRow | "new" | null>(null);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkText, setBulkText] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [ack, setAck] = useState(false);
  const [correctOpen, setCorrectOpen] = useState(false);
  const [reason, setReason] = useState("");

  const list = query.data;
  // Lists hold up to 2,000 rows: filter with a deferred term so typing stays responsive.
  const deferredSearch = useDeferredValue(search);

  const rows = useMemo(() => {
    if (!list) return [];
    const term = deferredSearch.trim().toLowerCase();
    return list.items.filter((r) => {
      const matchesSearch =
        !term ||
        [r.player_name, r.number, r.size, r.notes, ...list.schema.fields.map((f) => display(r, f))].some(
          (x) => (x ?? "").toLowerCase().includes(term),
        );
      const matchesSize = sizeFilter === "all" || r.size === sizeFilter;
      return matchesSearch && matchesSize;
    });
  }, [list, deferredSearch, sizeFilter]);
  // Parsed only when the pasted text (or the schema) changes, not on every render of the editor.
  const parsedBulk = useMemo(() => (list ? parseBulkRows(bulkText, list.schema.sizes, list.schema.fields) : null), [bulkText, list]);

  if (query.isPending) return <LoadingState label="Loading garment roster & sizing list…" />;
  if (query.error || !list) {
    return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  }

  const locked = list.current_version.status !== "draft";
  const review = list.review;
  const bulk = parsedBulk ?? parseBulkRows(bulkText, list.schema.sizes, list.schema.fields);

  const allocationPercent =
    review.expected_quantity > 0
      ? Math.min(100, Math.round((review.total_quantity / review.expected_quantity) * 100))
      : 100;
  const isAllocationExact = review.total_quantity === review.expected_quantity;
  const isOverallocated = review.total_quantity > review.expected_quantity;

  const sampleColumns = ["Size", "Quantity", ...list.schema.fields.map((f) => f.label)].join("\t");

  return (
    <div className="flex flex-col gap-6">
      {/* Executive Command Header */}
      <div className="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6 shadow-xs">
        {/* Breadcrumb + Metadata */}
        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
          <div className="flex items-center gap-2">
            <Link href="/orders/" className="hover:text-ink font-medium">
              Orders
            </Link>
            <span>/</span>
            <Link
              href={`/orders/view/?id=${list.order.id}`}
              className="text-brand font-semibold hover:underline inline-flex items-center gap-1 font-mono"
            >
              Job {list.order.order_no}
            </Link>
            <span>/</span>
            <span className="font-semibold text-ink">Garment List</span>
          </div>

          <div className="flex items-center gap-2">
            <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
              Model: {list.schema.product_type.name}
            </span>
            <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
              Version {list.current_version.version_no}
            </span>
          </div>
        </div>

        {/* Main Title Row & Actions */}
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-line pb-5">
          <div className="flex flex-col gap-1.5">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-2xl font-extrabold tracking-tight text-ink">
                Garment Roster & Size Allocation
              </h1>

              <Badge tone={locked ? "success" : "brand"} dot>
                {locked ? "Locked & Invoiced" : "Draft (Editable)"}
              </Badge>

              {isAllocationExact ? (
                <Badge tone="success" dot>
                  100% Allocated
                </Badge>
              ) : isOverallocated ? (
                <Badge tone="danger" dot>
                  Overallocated
                </Badge>
              ) : (
                <Badge tone="warning" dot>
                  Partial Allocation
                </Badge>
              )}
            </div>

            <p className="text-xs text-muted">
              Configure player roster names, sleeve numbers, and size distribution. Confirming locks the roster and issues the official sales invoice.
            </p>
          </div>

          {/* Action Buttons */}
          <div className="flex flex-wrap items-center gap-2">
            <a
              href={exportUrl(list.id)}
              className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 text-xs font-semibold text-ink shadow-xs hover:bg-subtle transition"
            >
              <DownloadIcon size={14} />
              <span>Export CSV</span>
            </a>

            {list.abilities.correct && (
              <Button
                variant="secondary"
                size="sm"
                icon={<PencilIcon size={13} />}
                onClick={() => setCorrectOpen(true)}
              >
                Administrative correction
              </Button>
            )}

            {list.abilities.confirm && (
              <Button
                size="sm"
                disabled={!review.can_confirm}
                onClick={() => setConfirmOpen(true)}
                className="shadow-xs"
              >
                Review & Confirm List
              </Button>
            )}
          </div>
        </div>

        {/* Allocation Hero Progress Card */}
        <div className="flex flex-col gap-3 rounded-xl border border-line bg-subtle/30 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <span className="text-xs font-bold text-ink">Volume Allocation:</span>
              <span className="text-xs font-semibold text-muted">
                {review.total_quantity} of {review.expected_quantity} pieces allocated across {review.rows} line items
              </span>
            </div>

            <span
              className={`text-xs font-bold ${
                isAllocationExact
                  ? "text-emerald-500"
                  : isOverallocated
                    ? "text-danger"
                    : "text-amber-500"
              }`}
            >
              {isAllocationExact
                ? "✓ Exact Order Allocation (Ready)"
                : isOverallocated
                  ? `Overallocated by ${review.total_quantity - review.expected_quantity} pcs`
                  : `${review.expected_quantity - review.total_quantity} pieces remaining to allocate`}
            </span>
          </div>

          {/* Progress Bar */}
          <div className="h-2 w-full overflow-hidden rounded-full bg-subtle">
            <div
              className={`h-full transition-all duration-300 rounded-full ${
                isAllocationExact
                  ? "bg-emerald-500"
                  : isOverallocated
                    ? "bg-danger"
                    : "bg-amber-500"
              }`}
              style={{ width: `${allocationPercent}%` }}
            />
          </div>
        </div>
      </div>

      {/* Locked Notice if confirmed */}
      {locked && (
        <div className="flex items-start gap-3 rounded-2xl border border-line bg-subtle/60 p-4 text-xs leading-relaxed text-muted shadow-xs">
          <CheckCircleIcon size={18} className="text-emerald-500 shrink-0 mt-0.5" />
          <div>
            <strong className="text-ink font-bold block mb-0.5">
              List Confirmed by {list.current_version.locked_by ?? "Administrator"} on{" "}
              {formatDateTime(list.current_version.locked_at)}
            </strong>
            <p>
              This garment specification list is locked and tied to the active sales invoice. Any subsequent adjustments require an Administrative Correction, which records an auditable revision.
            </p>
          </div>
        </div>
      )}

      {/* Validation Errors & Warnings Callouts (Only show errors when rows exist) */}
      {list.items.length > 0 && review.errors.length > 0 && (
        <div className="rounded-2xl border border-danger/30 bg-danger-soft/20 p-4 text-xs text-danger shadow-xs">
          <div className="flex items-center gap-2 font-bold text-sm mb-2">
            <AlertTriangleIcon size={16} className="shrink-0" />
            <span>Validation Errors to Resolve Before Confirmation:</span>
          </div>
          <ul className="list-disc pl-5 space-y-1">
            {review.errors.map((e, i) => (
              <li key={i}>
                {e.line_no ? <strong className="font-semibold">Row {e.line_no}: </strong> : ""}
                {e.message}
              </li>
            ))}
          </ul>
        </div>
      )}

      {review.warnings.length > 0 && (
        <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-900 shadow-xs">
          <div className="flex items-center gap-2 font-bold text-sm text-amber-950 mb-2">
            <AlertTriangleIcon size={16} className="text-amber-600 shrink-0" />
            <span>Warnings to Review and Acknowledge:</span>
          </div>
          <ul className="list-disc pl-5 space-y-1 text-ink">
            {review.warnings.map((w, i) => (
              <li key={i}>{w.message}</li>
            ))}
          </ul>
        </div>
      )}

      {/* Quick-Start Onboarding Hub when Roster is Empty; Analytics Cards when Roster has Items */}
      {list.items.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface p-6 shadow-xs flex flex-col gap-5">
          <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2">
              <span className="flex size-7 items-center justify-center rounded-lg bg-brand/10 text-brand font-bold text-xs">
                ⚡
              </span>
              <h3 className="text-base font-bold text-ink">Choose How to Add Garments & Player Roster</h3>
            </div>
            <p className="text-xs text-muted">
              Select one of the 3 streamlined options below to quickly build this order&apos;s garment list.
            </p>
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            {/* Option 1: Manual Single Player */}
            <div className="flex flex-col justify-between rounded-xl border border-line bg-subtle/30 p-5 hover:border-brand/40 transition">
              <div className="flex flex-col gap-3">
                <div className="flex size-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
                  <ShirtIcon size={22} />
                </div>
                <div>
                  <h4 className="text-sm font-bold text-ink">Manual Player Entry</h4>
                  <p className="mt-1 text-xs text-muted leading-relaxed">
                    Add players one by one with custom jersey names, numbers, sizing, and sleeve options.
                  </p>
                </div>
              </div>
              <div className="mt-5">
                <Button
                  size="sm"
                  icon={<PlusIcon size={14} />}
                  onClick={() => setEditing("new")}
                  disabled={locked || !list.abilities.edit}
                  className="w-full shadow-xs"
                >
                  Add First Player
                </Button>
              </div>
            </div>

            {/* Option 2: Bulk Paste */}
            <div className="flex flex-col justify-between rounded-xl border border-line bg-subtle/30 p-5 hover:border-brand/40 transition">
              <div className="flex flex-col gap-3">
                <div className="flex size-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-500">
                  <FileTextIcon size={22} />
                </div>
                <div>
                  <h4 className="text-sm font-bold text-ink">Copy & Paste Roster</h4>
                  <p className="mt-1 text-xs text-muted leading-relaxed">
                    Paste rows directly from Excel, Google Sheets, or WhatsApp with tab or comma separation.
                  </p>
                </div>
              </div>
              <div className="mt-5">
                <Button
                  size="sm"
                  variant="secondary"
                  icon={<FileTextIcon size={13} />}
                  onClick={() => setBulkOpen(true)}
                  disabled={locked || !list.abilities.edit}
                  className="w-full"
                >
                  Open Bulk Paste
                </Button>
              </div>
            </div>

            {/* Option 3: Import CSV */}
            <div className="flex flex-col justify-between rounded-xl border border-line bg-subtle/30 p-5 hover:border-brand/40 transition">
              <div className="flex flex-col gap-3">
                <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-500">
                  <DownloadIcon size={22} className="rotate-180" />
                </div>
                <div>
                  <h4 className="text-sm font-bold text-ink">Import CSV File</h4>
                  <p className="mt-1 text-xs text-muted leading-relaxed">
                    Upload a standard spreadsheet CSV file to auto-populate the whole team roster in seconds.
                  </p>
                </div>
              </div>
              <div className="mt-5">
                <label
                  className={`flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-line bg-surface text-xs font-semibold text-ink shadow-2xs hover:bg-subtle transition ${
                    locked || !list.abilities.edit ? "pointer-events-none opacity-50" : ""
                  }`}
                >
                  <DownloadIcon size={13} className="rotate-180" />
                  <span>Upload CSV Roster</span>
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    className="sr-only"
                    disabled={locked || !list.abilities.edit}
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) {
                        m.importCsv.mutate(
                          { list, file, mode: "replace" },
                          {
                            onSuccess: () => notify("Roster imported successfully", "success"),
                            onError: (err) => notify(errorOf(err) ?? "Import failed", "error"),
                          },
                        );
                      }
                      e.target.value = "";
                    }}
                  />
                </label>
              </div>
            </div>
          </div>
        </div>
      ) : (
        /* 3 Analytics Summary Cards */
        <div className="grid gap-4 md:grid-cols-3">
          {/* Card 1: Sizing Distribution Matrix */}
          <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between border-b border-line pb-3 mb-3">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  Size Breakdown ({review.by_size.length} Sizes)
                </span>
                <span className="text-xs text-muted">Click to filter</span>
              </div>

              <div className="flex flex-wrap gap-1.5">
                {review.by_size.map((s) => {
                  const isSelected = sizeFilter === s.size;
                  return (
                    <button
                      key={s.size_id}
                      type="button"
                      onClick={() => setSizeFilter(isSelected ? "all" : s.size)}
                      className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                        isSelected
                          ? "border-brand bg-brand text-white shadow-xs"
                          : "border-line bg-subtle/50 text-ink hover:border-brand/40 hover:bg-subtle"
                      }`}
                    >
                      <span>{s.size}:</span>
                      <span className={isSelected ? "text-white font-bold" : "text-brand font-bold"}>
                        {s.quantity}
                      </span>
                    </button>
                  );
                })}
              </div>
            </div>

            {sizeFilter !== "all" && (
              <div className="mt-3 pt-2 border-t border-line/60 flex items-center justify-between text-xs">
                <span className="text-brand font-semibold">Filtered by size: {sizeFilter}</span>
                <button
                  type="button"
                  onClick={() => setSizeFilter("all")}
                  className="text-muted hover:text-ink underline text-[11px]"
                >
                  Reset size filter
                </button>
              </div>
            )}
          </div>

          {/* Card 2: Price Groups */}
          <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between border-b border-line pb-3 mb-3">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  Price Groups & Classifications
                </span>
                <span className="text-xs text-muted">{review.price_groups.length} groups</span>
              </div>

              <ul className="text-xs space-y-2">
                {review.price_groups.map((g) => (
                  <li key={g.label} className="flex items-center justify-between">
                    <span className="text-muted truncate max-w-[180px]">{g.label}</span>
                    <span className="font-bold text-ink rounded bg-subtle px-2 py-0.5">
                      {g.quantity} pcs
                    </span>
                  </li>
                ))}
              </ul>
            </div>

            <p className="mt-3 text-[11px] text-muted border-t border-line/60 pt-2">
              Attributes affecting pricing matrix rules are calculated automatically.
            </p>
          </div>

          {/* Card 3: Commercial Pricing Preview */}
          <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between border-b border-line pb-3 mb-3">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  Commercial Pricing Preview
                </span>
                {list.pricing_preview.price_list && (
                  <Badge tone="brand">{list.pricing_preview.price_list}</Badge>
                )}
              </div>

              {list.pricing_preview.error ? (
                <p className="text-xs font-semibold text-danger">
                  {list.pricing_preview.error} {(list.pricing_preview.details ?? []).join(" ")}
                </p>
              ) : (
                <ul className="text-xs space-y-1.5">
                  {list.pricing_preview.lines.map((l) => (
                    <li key={l.description} className="flex items-center justify-between">
                      <span className="text-muted truncate max-w-[150px]">{l.description}</span>
                      <span className="font-medium text-ink font-mono text-[11px]">
                        {l.quantity} × {formatMoney(l.unit_price_minor, list.order.currency)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="mt-3 border-t border-line/60 pt-2 flex items-center justify-between font-extrabold text-sm text-ink">
              <span>Subtotal</span>
              <span>{formatMoney(list.pricing_preview.subtotal_minor, list.order.currency)}</span>
            </div>
          </div>
        </div>
      )}

      {/* Main Roster Workspace Table */}
      <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col gap-4">
        {/* Workspace Toolbar */}
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3 border-b border-line pb-4">
          <div className="flex items-center gap-3">
            <h3 className="text-sm font-bold text-ink">Player Roster & Sizing Entries</h3>
            <span className="rounded-full bg-subtle px-2 py-0.5 text-xs font-bold text-muted">
              {rows.length} of {list.items.length} rows
            </span>
          </div>

          {/* Quick actions for adding rows */}
          {list.abilities.edit && !locked && (
            <div className="flex flex-wrap items-center gap-2">
              <Button
                size="sm"
                icon={<PlusIcon size={14} />}
                onClick={() => setEditing("new")}
                className="shadow-xs"
              >
                Add player / row
              </Button>

              <Button
                size="sm"
                variant="secondary"
                icon={<FileTextIcon size={13} />}
                onClick={() => setBulkOpen(true)}
              >
                Bulk paste
              </Button>

              <label className="inline-flex h-8 cursor-pointer items-center gap-1 rounded-xl border border-line bg-surface px-3 text-xs font-semibold text-ink shadow-2xs hover:bg-subtle transition">
                <DownloadIcon size={12} className="rotate-180" />
                <span>Import CSV</span>
                <input
                  type="file"
                  accept=".csv,text/csv"
                  className="sr-only"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                      m.importCsv.mutate(
                        { list, file, mode: list.items.length ? "append" : "replace" },
                        {
                          onSuccess: () => notify("Roster imported successfully", "success"),
                          onError: (err) =>
                            notify(errorOf(err) ?? "Import failed", "error"),
                        },
                      );
                    }
                    e.target.value = "";
                  }}
                />
              </label>
            </div>
          )}
        </div>

        {/* Search & Filter bar */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="w-full sm:max-w-md">
            <div className="relative">
              <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                <SearchIcon size={15} />
              </span>
              <input
                type="text"
                placeholder="Filter by player name, number, size, or notes…"
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
          </div>

          {/* Size Pill filter (Only displayed when items exist, showing active sizes without overflow) */}
          {list.items.length > 0 && review.by_size.length > 0 && (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-xs font-medium text-muted mr-1">Size Filter:</span>
              <button
                type="button"
                onClick={() => setSizeFilter("all")}
                className={`rounded-lg px-2.5 py-1 text-xs font-medium transition cursor-pointer ${
                  sizeFilter === "all"
                    ? "bg-brand text-white font-semibold shadow-xs"
                    : "bg-subtle text-muted hover:text-ink hover:bg-subtle/80"
                }`}
              >
                All ({list.items.length})
              </button>
              {review.by_size.map((s) => {
                const isSelected = sizeFilter === s.size;
                return (
                  <button
                    key={s.size_id}
                    type="button"
                    onClick={() => setSizeFilter(isSelected ? "all" : s.size)}
                    className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium transition cursor-pointer ${
                      isSelected
                        ? "bg-brand text-white font-semibold shadow-xs"
                        : "bg-subtle text-ink hover:bg-subtle/80"
                    }`}
                  >
                    <span>{s.size}</span>
                    <span
                      className={`rounded px-1.5 py-0.2 text-[10px] font-bold ${
                        isSelected ? "bg-white/20 text-white" : "bg-brand/10 text-brand"
                      }`}
                    >
                      {s.quantity}
                    </span>
                    {isSelected && <span className="ml-0.5 text-[10px]">✕</span>}
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {/* Table View */}
        {rows.length === 0 ? (
          <div className="rounded-xl border border-dashed border-line bg-subtle/20 p-8 text-center">
            <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-subtle text-muted mb-3">
              {search || sizeFilter !== "all" ? <SearchIcon size={22} /> : <ShirtIcon size={24} />}
            </div>
            <h4 className="text-sm font-bold text-ink">
              {search || sizeFilter !== "all" ? "No matching garment items" : "No roster rows added yet"}
            </h4>
            <p className="text-xs text-muted max-w-sm mx-auto mt-1">
              {search || sizeFilter !== "all"
                ? "Try adjusting your search query or resetting the size filter."
                : "Add individual players, paste rows directly from Excel/Sheets, or import a CSV file using the options above."}
            </p>
            <div className="mt-4 flex justify-center gap-2">
              {search || sizeFilter !== "all" ? (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => {
                    setSearch("");
                    setSizeFilter("all");
                  }}
                >
                  Reset filters
                </Button>
              ) : (
                list.abilities.edit &&
                !locked && (
                  <>
                    <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => setEditing("new")}>
                      Add player
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => setBulkOpen(true)}>
                      Bulk paste
                    </Button>
                  </>
                )
              )}
            </div>
          </div>
        ) : (
          <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
            <table className="w-full min-w-[36rem] text-left text-xs">
              <thead className="border-b border-line bg-subtle/50 text-[11px] font-bold uppercase tracking-wider text-muted">
                <tr>
                  <th className="px-3.5 py-3">#</th>
                  <th className="px-3.5 py-3">Size</th>
                  <th className="px-3.5 py-3">Qty</th>
                  {list.schema.fields.some((f) => f.key === "player_name") && (
                    <th className="px-3.5 py-3">Player Name</th>
                  )}
                  {list.schema.fields.some((f) => f.key === "number") && (
                    <th className="px-3.5 py-3">Number</th>
                  )}
                  {list.schema.fields
                    .filter((f) => f.key !== "player_name" && f.key !== "number")
                    .map((f) => (
                      <th key={f.key} className="px-3.5 py-3">
                        {f.label}
                      </th>
                    ))}
                  <th className="px-3.5 py-3">Notes</th>
                  {list.abilities.edit && !locked && (
                    <th className="px-3.5 py-3 text-right">Actions</th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-line/70">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-subtle/40 transition">
                    <td className="px-3.5 py-2.5 font-mono text-muted text-xs font-semibold">
                      {r.line_no}
                    </td>

                    <td className="px-3.5 py-2.5">
                      <span className="rounded-md bg-brand/10 border border-brand/20 px-2 py-0.5 text-xs font-bold text-brand">
                        {r.size}
                      </span>
                    </td>

                    <td className="px-3.5 py-2.5 font-bold text-ink">{r.quantity}</td>

                    {list.schema.fields.some((f) => f.key === "player_name") && (
                      <td className="px-3.5 py-2.5 font-bold text-sm text-ink tracking-wide">
                        {r.player_name || <span className="text-muted font-normal italic">—</span>}
                      </td>
                    )}

                    {list.schema.fields.some((f) => f.key === "number") && (
                      <td className="px-3.5 py-2.5">
                        {r.number ? (
                          <span className="font-mono text-xs font-extrabold text-ink bg-subtle px-2 py-0.5 rounded border border-line">
                            {r.number}
                          </span>
                        ) : (
                          <span className="text-muted italic">—</span>
                        )}
                      </td>
                    )}

                    {list.schema.fields
                      .filter((f) => f.key !== "player_name" && f.key !== "number")
                      .map((f) => (
                        <td key={f.key} className="px-3.5 py-2.5 text-ink">
                          {display(r, f) || <span className="text-muted">—</span>}
                        </td>
                      ))}

                    <td className="px-3.5 py-2.5 text-muted text-xs max-w-xs truncate">
                      {r.notes ?? "—"}
                    </td>

                    {list.abilities.edit && !locked && (
                      <td className="px-3.5 py-2.5 text-right">
                        <div className="inline-flex items-center gap-1">
                          <Button
                            variant="ghost"
                            size="sm"
                            icon={<PencilIcon size={12} />}
                            onClick={() => setEditing(r)}
                            className="text-xs text-muted hover:text-ink"
                          >
                            Edit
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                              m.deleteRow.mutate(
                                { list, id: r.id },
                                {
                                  onError: (err) =>
                                    notify(errorOf(err) ?? "Delete failed", "error"),
                                },
                              )
                            }
                            className="text-xs text-danger hover:bg-danger-soft/20"
                          >
                            Delete
                          </Button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Version Audit History */}
      <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col gap-3">
        <div className="flex items-center justify-between border-b border-line pb-3">
          <div>
            <h3 className="text-sm font-bold text-ink">Version Revision History</h3>
            <p className="text-xs text-muted">
              All revisions and administrative corrections of this garment list are permanently archived.
            </p>
          </div>
          <Badge tone="neutral">{list.history.length} versions</Badge>
        </div>

        <ul className="divide-y divide-line/60 rounded-xl border border-line bg-surface text-xs">
          {list.history.map((h) => (
            <li
              key={h.version_no}
              className="flex flex-wrap items-center justify-between gap-3 p-3 hover:bg-subtle/20 transition"
            >
              <div className="flex items-center gap-2.5">
                <span className="font-bold text-ink font-mono text-sm">v{h.version_no}</span>
                <Badge
                  tone={h.status === "confirmed" ? "success" : "neutral"}
                  className="text-[10px]"
                >
                  {h.status}
                </Badge>
                <span className="text-muted font-medium">· {h.total_quantity} garments</span>
              </div>

              <div className="text-muted text-xs">
                {h.locked_by
                  ? `Confirmed by ${h.locked_by} on ${formatDateTime(h.locked_at)}`
                  : "Draft version"}
                {h.change_reason ? ` (Reason: "${h.change_reason}")` : ""}
              </div>
            </li>
          ))}
        </ul>
      </div>

      {/* Modal: Row Editor */}
      <Modal
        open={editing !== null}
        title={editing === "new" ? "Add Garment Item" : `Edit Row #${editing?.line_no}`}
        onClose={() => setEditing(null)}
      >
        {editing !== null && (
          <RowEditor
            list={list}
            row={editing === "new" ? null : editing}
            pending={m.addRows.isPending || m.updateRow.isPending}
            error={editing === "new" ? m.addRows.error : m.updateRow.error}
            onCancel={() => setEditing(null)}
            onSave={(row, andAddAnother) =>
              editing === "new"
                ? m.addRows.mutate(
                    { list, rows: [row] },
                    {
                      onSuccess: () => {
                        notify(
                          andAddAnother ? "Player added! Ready for next." : "Row added",
                          "success",
                        );
                        if (!andAddAnother) {
                          setEditing(null);
                        }
                      },
                      onError: (err) =>
                        notify(errorOf(err) ?? "Failed to add player row", "error"),
                    },
                  )
                : m.updateRow.mutate(
                    { list, id: editing.id, row },
                    {
                      onSuccess: () => {
                        notify("Row updated", "success");
                        setEditing(null);
                      },
                      onError: (err) =>
                        notify(errorOf(err) ?? "Failed to update row", "error"),
                    },
                  )
            }
          />
        )}
      </Modal>

      {/* Modal: Bulk Paste Entry */}
      <Modal open={bulkOpen} title="Bulk Paste Entry from Spreadsheet" onClose={() => setBulkOpen(false)}>
        <div className="flex flex-col gap-4">
          <div className="rounded-xl border border-line bg-subtle/30 p-3 text-xs text-muted leading-relaxed">
            <p className="font-bold text-ink mb-1">Expected Column Sequence:</p>
            <p className="font-mono text-[11px] text-brand bg-surface p-2 rounded-lg border border-line/60 overflow-x-auto">
              {sampleColumns}
            </p>
            <p className="mt-1 text-[11px]">
              Copy rows directly from Excel or Google Sheets and paste below. Tab-separated or comma-separated rows are both supported.
            </p>
          </div>

          <TextAreaField
            label="Spreadsheet Rows *"
            rows={8}
            placeholder={`e.g.\nM\t2\tD. SILVA\t07\nL\t1\tK. PERERA\t10`}
            value={bulkText}
            onChange={(e) => setBulkText(e.target.value)}
          />

          {bulk.rows.length > 0 && (
            <div className="flex items-center gap-2 text-xs text-emerald-500 font-semibold">
              <CheckCircleIcon size={14} />
              <span>{bulk.rows.length} valid row(s) recognized ready for import.</span>
            </div>
          )}

          {bulk.errors.length > 0 && (
            <div className="rounded-xl border border-danger/30 bg-danger-soft/20 p-3 text-xs text-danger">
              <p className="font-bold mb-1">Parsing Errors Detected:</p>
              <ul className="list-disc pl-4 space-y-0.5">
                {bulk.errors.slice(0, 5).map((err, i) => (
                  <li key={i}>{err}</li>
                ))}
              </ul>
            </div>
          )}

          <FormError message={errorOf(m.addRows.error)} />

          <div className="flex justify-end gap-2 pt-2 border-t border-line">
            <Button variant="secondary" onClick={() => setBulkOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={bulk.rows.length === 0 || bulk.errors.length > 0}
              loading={m.addRows.isPending}
              onClick={() =>
                m.addRows.mutate(
                  { list, rows: bulk.rows },
                  {
                    onSuccess: () => {
                      notify(`${bulk.rows.length} rows added successfully`, "success");
                      setBulkOpen(false);
                      setBulkText("");
                    },
                  },
                )
              }
            >
              Add {bulk.rows.length} rows
            </Button>
          </div>
        </div>
      </Modal>

      {/* Modal: Confirm and Lock List */}
      <Modal open={confirmOpen} title="Confirm and Lock Garment List" onClose={() => setConfirmOpen(false)}>
        <div className="flex flex-col gap-4 text-xs leading-relaxed">
          <div className="rounded-xl border border-line bg-subtle/30 p-4">
            <p className="font-bold text-ink text-sm mb-1">Confirmation Summary:</p>
            <p className="text-muted">
              You are locking <strong className="text-ink">{review.total_quantity} garments</strong> allocated across{" "}
              <strong className="text-ink">{review.rows} rows</strong>.
            </p>
            <p className="text-muted mt-1">
              Confirming permanently locks this version and generates the official commercial sales invoice for billing and production release.
            </p>
          </div>

          {review.warnings.length > 0 && (
            <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3">
              <Checkbox
                label={`I acknowledge and have verified all ${review.warnings.length} warning(s)`}
                checked={ack}
                onChange={(e) => setAck(e.target.checked)}
              />
            </div>
          )}

          <FormError message={errorOf(m.confirm.error)} />

          <div className="flex justify-end gap-2 pt-2 border-t border-line">
            <Button variant="secondary" onClick={() => setConfirmOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={review.warnings.length > 0 && !ack}
              loading={m.confirm.isPending}
              onClick={() =>
                m.confirm.mutate(
                  { list, acknowledge: ack },
                  {
                    onSuccess: (r) => {
                      notify(`List confirmed. Invoice ${r.invoice_no} issued.`, "success");
                      setConfirmOpen(false);
                    },
                  },
                )
              }
            >
              Confirm and lock list
            </Button>
          </div>
        </div>
      </Modal>

      {/* Modal: Administrative Correction */}
      <Modal open={correctOpen} title="Open Administrative Correction Version" onClose={() => setCorrectOpen(false)}>
        <div className="flex flex-col gap-4 text-xs leading-relaxed">
          <p className="text-muted">
            This opens an auditable draft revision (v{list.current_version.version_no + 1}) of the confirmed list for required adjustments. The active invoice remains valid until the revised version is confirmed.
          </p>

          <TextAreaField
            label="Reason for correction *"
            placeholder="e.g. Customer requested spelling change for player #07 from SILVA to DA SILVA"
            maxLength={500}
            rows={3}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />

          <FormError message={errorOf(m.correct.error)} />

          <div className="flex justify-end gap-2 pt-2 border-t border-line">
            <Button variant="secondary" onClick={() => setCorrectOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              disabled={reason.trim().length < 5}
              loading={m.correct.isPending}
              onClick={() =>
                m.correct.mutate(
                  { list, reason },
                  {
                    onSuccess: () => {
                      notify("Correction version opened", "success");
                      setCorrectOpen(false);
                      setReason("");
                    },
                  },
                )
              }
            >
              Open correction version
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

export function GarmentListScreen() {
  return (
    <WithIdParam>
      {(id) => (id === null ? <EmptyState title="Order not found" /> : <ListEditor orderId={id} />)}
    </WithIdParam>
  );
}
