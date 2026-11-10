"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { DownloadIcon, PlusIcon, SearchIcon, ScissorsIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { formatDateTime, formatMoney } from "@/lib/format";
import { exportUrl, type GarmentField, type GarmentList, type GarmentRow, parseBulkRows, type RowInput, useGarmentList, useGarmentMutations } from "./api";

const errorOf = (e: unknown) => (e instanceof ApiError ? [e.message, ...Object.values(e.fieldErrors).flat()].slice(0, 6).join(" ") : null);

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

function RowEditor({ list, row, onSave, onCancel, pending, error }: { list: GarmentList; row: Partial<GarmentRow> | null; onSave: (r: RowInput) => void; onCancel: () => void; pending: boolean; error: unknown }) {
  const fields = list.schema.fields;
  const [v, setV] = useState<Record<string, string>>(() => {
    const base: Record<string, string> = { size_id: String(row?.size_id ?? ""), quantity: String(row?.quantity ?? 1), notes: row?.notes ?? "" };
    fields.forEach((f) => (base[f.key] = row ? valueOf(row as GarmentRow, f) : ""));
    return base;
  });
  const set = (k: string, val: string) => setV((p) => ({ ...p, [k]: val }));
  const save = () => {
    const values: Record<string, string> = {};
    fields.filter((f) => f.key !== "player_name" && f.key !== "number" && v[f.key] !== "").forEach((f) => (values[f.key] = v[f.key]));
    onSave({ size_id: v.size_id ? Number(v.size_id) : null, quantity: Number(v.quantity) || 1, player_name: v.player_name || null, number: v.number || null, notes: v.notes || null, values });
  };

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorOf(error)} />
      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField label="Size *" options={[{ value: "", label: "Select size" }, ...list.schema.sizes.map((s) => ({ value: String(s.id), label: s.label }))]} value={v.size_id} onChange={(e) => set("size_id", e.target.value)} />
        <TextField label="Quantity" inputMode="numeric" value={v.quantity} onChange={(e) => set("quantity", e.target.value)} />
        {fields.map((f) =>
          f.input_type === "select" || f.input_type === "boolean" ? (
            <SelectField
              key={f.key}
              label={`${f.label}${f.is_required ? " *" : ""}`}
              options={[{ value: "", label: "—" }, ...(f.input_type === "boolean" ? [{ value: "yes", label: "Yes" }, { value: "no", label: "No" }] : f.options.map((o) => ({ value: o.code, label: o.label })))]}
              value={v[f.key]}
              onChange={(e) => set(f.key, e.target.value)}
            />
          ) : (
            <TextField key={f.key} label={`${f.label}${f.is_required ? " *" : ""}`} inputMode={f.input_type === "number" ? "numeric" : undefined} maxLength={f.max_length ?? 120} value={v[f.key]} onChange={(e) => set(f.key, e.target.value)} />
          ),
        )}
      </div>
      <TextField label="Notes" maxLength={255} value={v.notes} onChange={(e) => set("notes", e.target.value)} />
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onCancel}>Cancel</Button>
        <Button loading={pending} onClick={save}>Save row</Button>
      </div>
    </div>
  );
}

function ListEditor({ orderId }: { orderId: number }) {
  const query = useGarmentList(orderId);
  const m = useGarmentMutations(orderId);
  const { notify } = useToast();
  const [search, setSearch] = useState("");
  const [editing, setEditing] = useState<GarmentRow | "new" | null>(null);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkText, setBulkText] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [ack, setAck] = useState(false);
  const [correctOpen, setCorrectOpen] = useState(false);
  const [reason, setReason] = useState("");

  const list = query.data;
  const rows = useMemo(() => {
    if (!list) return [];
    const term = search.trim().toLowerCase();
    if (!term) return list.items;
    return list.items.filter((r) => [r.player_name, r.number, r.size, ...list.schema.fields.map((f) => display(r, f))].some((x) => (x ?? "").toLowerCase().includes(term)));
  }, [list, search]);

  if (query.isPending) return <LoadingState />;
  if (query.error || !list) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;

  const locked = list.current_version.status !== "draft";
  const review = list.review;
  const bulk = parseBulkRows(bulkText, list.schema.sizes, list.schema.fields);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Name / number list — ${list.order.order_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/orders/" className="hover:text-ink">Orders</Link>
            <span>/</span>
            <Link href={`/orders/view/?id=${list.order.id}`} className="hover:text-ink">Job {list.order.order_no}</Link>
            <span>/</span>
            <span className="font-semibold text-ink">Garment List</span>
          </div>
        }
        description={`${list.schema.product_type.name} · Version ${list.current_version.version_no}${list.current_version.change_reason ? ` (correction: ${list.current_version.change_reason})` : ""}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Badge tone={locked ? "success" : "neutral"} dot>
              {locked ? `Confirmed by ${list.current_version.locked_by}` : "Draft (Editable)"}
            </Badge>
            <a href={exportUrl(list.id)} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-semibold text-ink shadow-xs hover:bg-subtle transition">
              <DownloadIcon size={14} />
              <span>Export CSV</span>
            </a>
            {list.abilities.correct && (
              <Button variant="secondary" size="sm" onClick={() => setCorrectOpen(true)}>
                Administrative correction
              </Button>
            )}
          </div>
        }
      />

      {locked && (
        <div className="rounded-xl border border-line bg-subtle/60 p-4 text-xs leading-relaxed text-muted">
          <strong className="text-ink">List Confirmed:</strong> This garment specification list is locked and tied to the active invoice. Modifications require an administrative correction, which will open an auditable new version.
        </div>
      )}

      {/* Summary Cards */}
      <Section title="List Summary & Pricing Analysis" description={`${review.total_quantity} of ${review.expected_quantity} garments allocated across ${review.rows} rows.`}>
        <div className="grid gap-4 md:grid-cols-3">
          <div className="rounded-xl border border-line bg-subtle/40 p-4">
            <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-muted">Breakdown by Size</p>
            <div className="flex flex-wrap gap-1.5">
              {review.by_size.map((s) => (
                <span key={s.size_id} className="rounded-md border border-line bg-surface px-2 py-1 text-xs font-semibold text-ink">
                  {s.size}: <span className="text-brand">{s.quantity}</span>
                </span>
              ))}
            </div>
          </div>
          <div className="rounded-xl border border-line bg-subtle/40 p-4">
            <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-muted">Price Groups</p>
            <ul className="text-xs space-y-1">
              {review.price_groups.map((g) => (
                <li key={g.label} className="flex justify-between">
                  <span className="text-muted">{g.label}:</span>
                  <span className="font-semibold text-ink">{g.quantity} pcs</span>
                </li>
              ))}
            </ul>
          </div>
          <div className="rounded-xl border border-line bg-subtle/40 p-4">
            <p className="mb-2 text-[11px] font-bold uppercase tracking-wider text-muted">
              Pricing Preview {list.pricing_preview.price_list ? `(${list.pricing_preview.price_list})` : ""}
            </p>
            {list.pricing_preview.error ? (
              <p className="text-xs font-semibold text-danger">{list.pricing_preview.error} {(list.pricing_preview.details ?? []).join(" ")}</p>
            ) : (
              <div className="flex flex-col justify-between h-full gap-2 text-xs">
                <ul className="space-y-1">
                  {list.pricing_preview.lines.map((l) => (
                    <li key={l.description} className="flex justify-between">
                      <span className="text-muted truncate max-w-[140px]">{l.description}</span>
                      <span className="font-semibold text-ink">{l.quantity} × {formatMoney(l.unit_price_minor, list.order.currency)}</span>
                    </li>
                  ))}
                </ul>
                <div className="border-t border-line/60 pt-2 flex justify-between font-bold text-sm text-ink">
                  <span>Subtotal</span>
                  <span>{formatMoney(list.pricing_preview.subtotal_minor, list.order.currency)}</span>
                </div>
              </div>
            )}
          </div>
        </div>

        {review.errors.length > 0 && (
          <div className="mt-4 rounded-xl border border-danger/30 bg-danger-soft p-3">
            <p className="text-xs font-bold text-danger mb-1">Validation Errors Before Confirmation:</p>
            <ul className="list-disc pl-5 text-xs text-danger space-y-0.5">
              {review.errors.map((e, i) => (
                <li key={i}>{e.line_no ? `Row ${e.line_no}: ` : ""}{e.message}</li>
              ))}
            </ul>
          </div>
        )}

        {review.warnings.length > 0 && (
          <div className="mt-3 rounded-xl border border-warning/30 bg-warning-soft p-3">
            <p className="text-xs font-bold text-warning mb-1">Warnings to Acknowledge:</p>
            <ul className="list-disc pl-5 text-xs text-ink space-y-0.5">
              {review.warnings.map((w, i) => (
                <li key={i}>{w.message}</li>
              ))}
            </ul>
          </div>
        )}

        {list.abilities.confirm && (
          <div className="mt-4">
            <Button disabled={!review.can_confirm} onClick={() => setConfirmOpen(true)}>
              Review and confirm list
            </Button>
          </div>
        )}
      </Section>

      {/* Rows Table */}
      <Section
        title="Garment Specifications & Player Names"
        actions={
          list.abilities.edit && !locked ? (
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => setEditing("new")}>
                Add row
              </Button>
              <Button size="sm" variant="secondary" onClick={() => setBulkOpen(true)}>
                Bulk entry
              </Button>
              <label className="inline-flex h-8 cursor-pointer items-center rounded-lg border border-line bg-surface px-3 text-xs font-semibold text-ink shadow-xs hover:bg-subtle transition">
                Import CSV
                <input
                  type="file"
                  accept=".csv,text/csv"
                  className="sr-only"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) m.importCsv.mutate({ list, file, mode: list.items.length ? "append" : "replace" }, { onSuccess: () => notify("Rows imported", "success"), onError: (err) => notify(errorOf(err) ?? "Import failed", "error") });
                    e.target.value = "";
                  }}
                />
              </label>
            </div>
          ) : undefined
        }
      >
        <div className="mb-4 sm:max-w-xs">
          <TextField label="Search list" placeholder="Filter by player name, number, or size…" icon={<SearchIcon size={14} />} value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        {rows.length === 0 ? (
          <EmptyState title={list.items.length ? "No matching rows" : "No garment items added yet"} description="Add individual items or use bulk paste/CSV import." />
        ) : (
          <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
            <table className="w-full min-w-[36rem] text-left text-sm">
              <thead className="border-b border-line bg-subtle/60 text-xs font-semibold uppercase tracking-wider text-muted">
                <tr>
                  <th className="px-3 py-3">#</th>
                  <th className="px-3 py-3">Size</th>
                  <th className="px-3 py-3">Qty</th>
                  {list.schema.fields.map((f) => (
                    <th key={f.key} className="px-3 py-3">{f.label}</th>
                  ))}
                  {list.abilities.edit && !locked && <th className="px-3 py-3 text-right">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-line/70">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-subtle/50 transition">
                    <td className="px-3 py-2.5 font-mono text-xs text-muted">{r.line_no}</td>
                    <td className="px-3 py-2.5 font-bold text-ink">{r.size}</td>
                    <td className="px-3 py-2.5 font-semibold">{r.quantity}</td>
                    {list.schema.fields.map((f) => (
                      <td key={f.key} className="px-3 py-2.5 text-ink">{display(r, f)}</td>
                    ))}
                    {list.abilities.edit && !locked && (
                      <td className="px-3 py-2.5 text-right">
                        <div className="inline-flex items-center gap-1">
                          <Button variant="ghost" size="sm" onClick={() => setEditing(r)}>Edit</Button>
                          <Button variant="ghost" size="sm" className="text-danger hover:bg-danger-soft" onClick={() => m.deleteRow.mutate({ list, id: r.id }, { onError: (err) => notify(errorOf(err) ?? "Delete failed", "error") })}>Delete</Button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      {/* Version History */}
      <Section title="Version Audit History" description="All revisions of this list are saved immutably.">
        <ul className="divide-y divide-line/60 rounded-xl border border-line bg-surface text-xs">
          {list.history.map((h) => (
            <li key={h.version_no} className="flex flex-wrap items-center justify-between gap-2 p-3">
              <div className="flex items-center gap-2">
                <span className="font-bold text-ink">v{h.version_no}</span>
                <Badge tone={h.status === "confirmed" ? "success" : "neutral"} className="text-[10px]">{h.status}</Badge>
                <span className="text-muted">· {h.total_quantity} garments</span>
              </div>
              <div className="text-muted">
                {h.locked_by ? `Confirmed by ${h.locked_by} on ${formatDateTime(h.locked_at)}` : "Draft version"}
                {h.change_reason ? ` (Reason: ${h.change_reason})` : ""}
              </div>
            </li>
          ))}
        </ul>
      </Section>

      {/* Modals */}
      <Modal open={editing !== null} title={editing === "new" ? "Add Garment Item" : "Edit Garment Item"} onClose={() => setEditing(null)}>
        {editing !== null && (
          <RowEditor
            list={list}
            row={editing === "new" ? null : editing}
            pending={m.addRows.isPending || m.updateRow.isPending}
            error={editing === "new" ? m.addRows.error : m.updateRow.error}
            onCancel={() => setEditing(null)}
            onSave={(row) =>
              editing === "new"
                ? m.addRows.mutate({ list, rows: [row] }, { onSuccess: () => setEditing(null) })
                : m.updateRow.mutate({ list, id: editing.id, row }, { onSuccess: () => setEditing(null) })
            }
          />
        )}
      </Modal>

      <Modal open={bulkOpen} title="Bulk Paste Entry" onClose={() => setBulkOpen(false)}>
        <div className="flex flex-col gap-3">
          <p className="text-xs text-muted leading-relaxed">
            Paste one row per line from your spreadsheet (columns: Size, Quantity, {list.schema.fields.map((f) => f.label).join(", ")}):
          </p>
          <TextAreaField label="Spreadsheet data" rows={10} value={bulkText} onChange={(e) => setBulkText(e.target.value)} />
          {bulk.errors.length > 0 && <FormError message={bulk.errors.slice(0, 5).join(" ")} />}
          <FormError message={errorOf(m.addRows.error)} />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setBulkOpen(false)}>Cancel</Button>
            <Button disabled={bulk.rows.length === 0 || bulk.errors.length > 0} loading={m.addRows.isPending} onClick={() => m.addRows.mutate({ list, rows: bulk.rows }, { onSuccess: () => (notify(`${bulk.rows.length} rows added`, "success"), setBulkOpen(false), setBulkText("")) })}>
              Add {bulk.rows.length} rows
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={confirmOpen} title="Confirm and Lock the List" onClose={() => setConfirmOpen(false)}>
        <div className="flex flex-col gap-3 text-xs leading-relaxed">
          <p className="text-ink">
            You are locking <strong>{review.total_quantity} garments</strong> in <strong>{review.rows} rows</strong>. Confirming locks this version and generates an immutable official invoice.
          </p>
          {review.warnings.length > 0 && (
            <div className="rounded-xl border border-warning/30 bg-warning-soft p-3">
              <Checkbox label={`I acknowledge and have reviewed all ${review.warnings.length} warning(s)`} checked={ack} onChange={(e) => setAck(e.target.checked)} />
            </div>
          )}
          <FormError message={errorOf(m.confirm.error)} />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setConfirmOpen(false)}>Cancel</Button>
            <Button disabled={review.warnings.length > 0 && !ack} loading={m.confirm.isPending} onClick={() => m.confirm.mutate({ list, acknowledge: ack }, { onSuccess: (r) => (notify(`List confirmed. Invoice ${r.invoice_no} issued.`, "success"), setConfirmOpen(false)) })}>
              Confirm and lock
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={correctOpen} title="Administrative Correction" onClose={() => setCorrectOpen(false)}>
        <div className="flex flex-col gap-3 text-xs leading-relaxed">
          <p className="text-muted">
            This opens an auditable draft copy of the confirmed list for adjustments. The existing invoice remains valid until the revised list is confirmed.
          </p>
          <TextAreaField label="Reason for correction *" placeholder="e.g. Customer changed player surname from Smith to Smyth" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          <FormError message={errorOf(m.correct.error)} />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setCorrectOpen(false)}>Cancel</Button>
            <Button variant="danger" disabled={reason.trim().length < 5} loading={m.correct.isPending} onClick={() => m.correct.mutate({ list, reason }, { onSuccess: () => (notify("Correction version opened", "success"), setCorrectOpen(false), setReason("")) })}>
              Open correction version
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

export function GarmentListScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Order not found" /> : <ListEditor orderId={id} />)}</WithIdParam>;
}
