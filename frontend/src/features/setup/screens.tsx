"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, StatusBadge } from "@/components/ui/form-controls";
import { FileTextIcon, PlusIcon, TagIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { useProductCategories } from "@/features/orders/api";
import { useBranches, usePages } from "@/features/organizations/api";
import { ApiError } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime, formatMoney, minorToInput, parseMoneyToMinor } from "@/lib/format";
import { type PriceRule, useGarmentSetup, useInvoice, useMaterials, usePriceList, usePriceListMutations, usePriceLists, useSaveWorkflowSettings, useSetupMutation, useWorkflowSettings } from "./api";

const errorText = (e: unknown) => (e instanceof ApiError ? [e.message, ...Object.values(e.fieldErrors).flat()].slice(0, 4).join(" ") : null);

/* ---------- Workflow settings ---------- */

export function WorkflowSettingsScreen() {
  const query = useWorkflowSettings();
  const save = useSaveWorkflowSettings();
  const { notify } = useToast();
  const [v, setV] = useState<{
    designer: string;
    bulk: string;
    autoDesigner: boolean;
    autoBulk: boolean;
    rounds: string;
  } | null>(null);

  return (
    <RequirePermission anyOf={["settings.manage"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Workflow settings" description="Who receives new design and bulk jobs, automatic workload balancing, and revision guardrails." />
        {query.isPending ? (
          <LoadingState />
        ) : query.error ? (
          <ErrorState error={query.error} />
        ) : (
          (() => {
            const s = query.data;
            const val = v ?? {
              designer: String(s.default_designer_id ?? ""),
              bulk: String(s.default_bulk_designer_id ?? ""),
              autoDesigner: Boolean(s.auto_assign_designer),
              autoBulk: Boolean(s.auto_assign_bulk_designer),
              rounds: String(s.max_correction_rounds),
            };

            const designerOptions = [
              { value: "", label: "None (assign manually)" },
              ...s.designers.map((d) => ({
                value: String(d.id),
                label: `${d.name}${d.active_tasks !== undefined ? ` (${d.active_tasks} active task${d.active_tasks === 1 ? "" : "s"})${d.is_free ? " · Free" : ""}` : ""}`,
              })),
            ];

            const bulkOptions = [
              { value: "", label: "None (assign manually)" },
              ...s.bulk_designers.map((d) => ({
                value: String(d.id),
                label: `${d.name}${d.active_jobs !== undefined ? ` (${d.active_jobs} active job${d.active_jobs === 1 ? "" : "s"})${d.is_free ? " · Free" : ""}` : ""}`,
              })),
            ];

            return (
              <Section title="Design & Bulk Responsibilities" description="Configure automatic designer assignment rules, workload distribution, and revision guardrails.">
                <FormError message={errorText(save.error)} />
                <div className="flex flex-col gap-6">
                  {/* Design Team */}
                  <div className="rounded-xl border border-line bg-surface/50 p-4">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                      <div>
                        <h3 className="text-sm font-semibold text-ink">Design Artwork Assignment</h3>
                        <p className="text-xs text-muted mt-0.5">Automatically assigns new orders to the least-loaded designer to prevent bottlenecks.</p>
                      </div>
                      <label className="flex items-center gap-2 cursor-pointer select-none">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-line text-brand focus:ring-brand/20"
                          checked={val.autoDesigner}
                          onChange={(e) => setV({ ...val, autoDesigner: e.target.checked })}
                        />
                        <span className="text-xs font-medium text-ink">Auto-assign free designer</span>
                      </label>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <SelectField
                        label={val.autoDesigner ? "Default / Preferred designer (tie-breaker)" : "Responsible design designer"}
                        hint={val.autoDesigner ? "Used when all eligible designers have equal workloads." : "Always assigned when a new order design task is created."}
                        options={designerOptions}
                        value={val.designer}
                        onChange={(e) => setV({ ...val, designer: e.target.value })}
                      />
                      <TextField
                        label="Design correction rounds allowed"
                        hint="Maximum customer correction rounds before admin override is required."
                        inputMode="numeric"
                        value={val.rounds}
                        onChange={(e) => setV({ ...val, rounds: e.target.value })}
                      />
                    </div>
                  </div>

                  {/* Bulk Team */}
                  <div className="rounded-xl border border-line bg-surface/50 p-4">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                      <div>
                        <h3 className="text-sm font-semibold text-ink">Bulk Production Assignment</h3>
                        <p className="text-xs text-muted mt-0.5">Automatically balances bulk production measurement and checklist workload among Bulk Designers.</p>
                      </div>
                      <label className="flex items-center gap-2 cursor-pointer select-none">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-line text-brand focus:ring-brand/20"
                          checked={val.autoBulk}
                          onChange={(e) => setV({ ...val, autoBulk: e.target.checked })}
                        />
                        <span className="text-xs font-medium text-ink">Auto-assign free bulk designer</span>
                      </label>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <SelectField
                        label={val.autoBulk ? "Default / Preferred bulk designer (tie-breaker)" : "Responsible bulk designer"}
                        hint={val.autoBulk ? "Used when all eligible bulk designers have equal workloads." : "Default assignee when bulk production job is opened."}
                        options={bulkOptions}
                        value={val.bulk}
                        onChange={(e) => setV({ ...val, bulk: e.target.value })}
                      />
                    </div>
                  </div>
                </div>

                <div className="mt-5 flex justify-end">
                  <Button
                    loading={save.isPending}
                    onClick={() =>
                      save.mutate(
                        {
                          default_designer_id: val.designer ? Number(val.designer) : null,
                          default_bulk_designer_id: val.bulk ? Number(val.bulk) : null,
                          auto_assign_designer: val.autoDesigner,
                          auto_assign_bulk_designer: val.autoBulk,
                          max_correction_rounds: Number(val.rounds),
                        },
                        { onSuccess: () => (notify("Settings saved", "success"), setV(null)) },
                      )
                    }
                  >
                    Save settings
                  </Button>
                </div>
              </Section>
            );
          })()
        )}
      </div>
    </RequirePermission>
  );
}

/* ---------- Garment list fields and sizes ---------- */

export { GarmentSetupScreen } from "./garment-setup-screen";

/* ---------- Price lists ---------- */

export function PriceListsScreen() {
  const lists = usePriceLists();
  const pages = usePages({ per_page: 100, sort: "name" });
  const { create } = usePriceListMutations(null);
  const { can } = useAuth();
  const { notify } = useToast();
  const [open, setOpen] = useState(false);
  const [v, setV] = useState({ page_id: "", name: "", valid_from: "", valid_to: "", copy_from_id: "" });

  return (
    <RequirePermission anyOf={["pricing.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Price lists"
          description="Page-specific prices with effective dates. A list used by an invoice is frozen: change prices with a new list from a later date."
          actions={can("pricing.manage") ? <Button icon={<PlusIcon size={16} />} onClick={() => setOpen(true)}>New price list</Button> : undefined}
        />
        {lists.isPending ? (
          <LoadingState />
        ) : lists.error ? (
          <ErrorState error={lists.error} />
        ) : (lists.data ?? []).length === 0 ? (
          <EmptyState title="No price lists configured" description="Invoices cannot be issued until the sales channel has an active price list." />
        ) : (
          <ul className="divide-y divide-line/60 rounded-xl border border-line bg-surface text-sm shadow-xs">
            {(lists.data ?? []).map((l) => (
              <li key={l.id} className="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-subtle/50 transition">
                <Link className="font-semibold text-brand hover:underline" href={`/settings/pricing/view/?id=${l.id}`}>
                  {l.page?.code} · {l.name}
                </Link>
                <div className="flex items-center gap-2 text-xs text-muted">
                  <span>{formatBusinessDate(l.valid_from)} – {l.valid_to ? formatBusinessDate(l.valid_to) : "Ongoing"}</span>
                  <Badge tone="neutral">{l.rules_count} rules</Badge>
                  {l.in_use && <Badge tone="brand">Used by invoices</Badge>}
                  {!l.is_active && <Badge tone="danger">Inactive</Badge>}
                </div>
              </li>
            ))}
          </ul>
        )}
        <Modal open={open} title="Create Price List" onClose={() => setOpen(false)}>
          <div className="flex flex-col gap-4">
            <FormError message={errorText(create.error)} />
            <SelectField label="Sales channel (Page)" options={[{ value: "", label: "Select page" }, ...(pages.data?.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} value={v.page_id} onChange={(e) => setV({ ...v, page_id: e.target.value })} />
            <TextField label="List name" placeholder="e.g. Standard 2026 Season" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
            <TextField label="Effective from date" type="date" value={v.valid_from} onChange={(e) => setV({ ...v, valid_from: e.target.value })} />
            <TextField label="Effective until (optional)" type="date" value={v.valid_to} onChange={(e) => setV({ ...v, valid_to: e.target.value })} />
            <SelectField label="Copy existing rules from" options={[{ value: "", label: "Start empty" }, ...(lists.data ?? []).filter((l) => String(l.page?.id) === v.page_id).map((l) => ({ value: String(l.id), label: l.name }))]} value={v.copy_from_id} onChange={(e) => setV({ ...v, copy_from_id: e.target.value })} />
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
              <Button loading={create.isPending} onClick={() => create.mutate({ page_id: Number(v.page_id), name: v.name, valid_from: v.valid_from, valid_to: v.valid_to || null, copy_from_id: v.copy_from_id ? Number(v.copy_from_id) : null }, { onSuccess: () => (notify("Price list created", "success"), setOpen(false)) })}>Create list</Button>
            </div>
          </div>
        </Modal>
      </div>
    </RequirePermission>
  );
}

function RuleForm({ listId, rule, onDone }: { listId: number; rule: PriceRule | null; onDone: () => void }) {
  const { saveRule } = usePriceListMutations(listId);
  const categories = useProductCategories(false);
  const materials = useMaterials(false);
  const branches = useBranches({ per_page: 100, sort: "name" });
  const setup = useGarmentSetup();
  const [v, setV] = useState({
    product_type_id: String(rule?.product_type.id ?? ""), material_id: String(rule?.material?.id ?? ""), branch_id: String(rule?.branch?.id ?? ""), label: rule?.label ?? "",
    min_qty: String(rule?.min_qty ?? 1), max_qty: rule?.max_qty ? String(rule.max_qty) : "", price: rule ? minorToInput(rule.unit_price_minor) : "",
    conditions: Object.fromEntries((rule?.conditions ?? []).map((c) => [c.field_key, c.option_code])) as Record<string, string>,
  });
  const pricedFields = setup.data?.categories.find((c) => String(c.id) === v.product_type_id)?.fields.filter((f) => f.affects_price && f.is_active) ?? [];
  const opts = (setId: number | null) => setup.data?.option_sets.find((s) => s.id === setId)?.options ?? [];

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorText(saveRule.error)} />
      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField label="Category *" options={[{ value: "", label: "Select category" }, ...(categories.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))]} value={v.product_type_id} onChange={(e) => setV({ ...v, product_type_id: e.target.value, conditions: {} })} />
        <SelectField label="Material" options={[{ value: "", label: "Any material" }, ...(materials.data ?? []).map((m) => ({ value: String(m.id), label: m.name }))]} value={v.material_id} onChange={(e) => setV({ ...v, material_id: e.target.value })} />
        <TextField label="Rule Label" placeholder="e.g. Standard Sublimation" value={v.label} onChange={(e) => setV({ ...v, label: e.target.value })} />
        <TextField label="From quantity *" inputMode="numeric" value={v.min_qty} onChange={(e) => setV({ ...v, min_qty: e.target.value })} />
        <TextField label="To quantity" inputMode="numeric" placeholder="Leave empty for unlimited" value={v.max_qty} onChange={(e) => setV({ ...v, max_qty: e.target.value })} />
        <TextField label="Unit price (LKR) *" inputMode="decimal" value={v.price} onChange={(e) => setV({ ...v, price: e.target.value })} />
        {pricedFields.map((f) => (
          <SelectField key={f.key} label={f.label} options={[{ value: "", label: "Any" }, ...(f.input_type === "boolean" ? [{ code: "yes", label: "Yes" }, { code: "no", label: "No" }] : opts(f.garment_option_set_id)).map((o) => ({ value: o.code, label: o.label }))]} value={v.conditions[f.key] ?? ""} onChange={(e) => setV({ ...v, conditions: { ...v.conditions, [f.key]: e.target.value } })} />
        ))}
      </div>
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          loading={saveRule.isPending}
          onClick={() =>
            saveRule.mutate(
              {
                ruleId: rule?.id, product_type_id: Number(v.product_type_id), material_id: v.material_id ? Number(v.material_id) : null, branch_id: v.branch_id ? Number(v.branch_id) : null,
                label: v.label || null, min_qty: Number(v.min_qty), max_qty: v.max_qty ? Number(v.max_qty) : null, unit_price_minor: parseMoneyToMinor(v.price) ?? -1,
                conditions: Object.entries(v.conditions).filter(([, code]) => code).map(([field_key, option_code]) => ({ field_key, option_code })),
              },
              { onSuccess: onDone },
            )
          }
        >
          Save rule
        </Button>
      </div>
    </div>
  );
}

function PriceListDetail({ id }: { id: number }) {
  const query = usePriceList(id);
  const { deleteRule, update } = usePriceListMutations(id);
  const [editing, setEditing] = useState<PriceRule | "new" | null>(null);
  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} />;
  const l = query.data;
  const editable = l.abilities.update && !l.in_use;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`${l.page?.code} · ${l.name}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/settings/pricing/" className="hover:text-ink">Price Lists</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{l.name}</span>
          </div>
        }
        description={`${formatBusinessDate(l.valid_from)} – ${l.valid_to ? formatBusinessDate(l.valid_to) : "Ongoing"}${l.in_use ? " · Frozen: currently in use by issued invoices" : ""}`}
        actions={
          l.abilities.update ? (
            <div className="flex gap-2">
              {editable && <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => setEditing("new")}>Add rule</Button>}
              <Button size="sm" variant="secondary" onClick={() => update.mutate({ is_active: !l.is_active })}>{l.is_active ? "Deactivate" : "Activate"}</Button>
            </div>
          ) : undefined
        }
      />
      <FormError message={errorText(deleteRule.error) ?? errorText(update.error)} />
      {(l.rules ?? []).length === 0 ? (
        <EmptyState title="No pricing rules configured" description="Enter pricing rules per category, fabric, conditions, and quantity volume tiers." />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
          <table className="w-full min-w-[36rem] text-left text-sm">
            <thead className="border-b border-line bg-subtle/60 text-xs font-semibold uppercase tracking-wider text-muted">
              <tr>
                <th className="px-4 py-3">Category</th>
                <th className="px-4 py-3">Conditions</th>
                <th className="px-4 py-3">Material</th>
                <th className="px-4 py-3">Quantity Tier</th>
                <th className="px-4 py-3">Unit Price</th>
                {editable && <th className="px-4 py-3 text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-line/70">
              {(l.rules ?? []).map((r) => (
                <tr key={r.id} className="hover:bg-subtle/50 transition">
                  <td className="px-4 py-3 font-semibold text-ink">
                    {r.product_type.name}
                    {r.label && <span className="block text-xs font-normal text-muted">{r.label}</span>}
                  </td>
                  <td className="px-4 py-3 text-xs text-muted">
                    {r.conditions.map((c) => `${c.field_key}=${c.option_code}`).join(", ") || "Any"}
                  </td>
                  <td className="px-4 py-3 text-xs text-muted">
                    {r.material?.name ?? "Any"}
                  </td>
                  <td className="px-4 py-3 font-semibold text-ink">
                    {r.min_qty}{r.max_qty ? `–${r.max_qty}` : "+"} pcs
                  </td>
                  <td className="px-4 py-3 font-bold text-ink">{formatMoney(r.unit_price_minor, l.currency)}</td>
                  {editable && (
                    <td className="px-4 py-3 text-right">
                      <div className="inline-flex items-center gap-1">
                        <Button variant="ghost" size="sm" onClick={() => setEditing(r)}>Edit</Button>
                        <Button variant="ghost" size="sm" className="text-danger hover:bg-danger-soft" onClick={() => deleteRule.mutate(r.id)}>Delete</Button>
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Modal open={editing !== null} title={editing === "new" ? "Add Price Rule" : "Edit Price Rule"} onClose={() => setEditing(null)}>
        {editing !== null && <RuleForm listId={id} rule={editing === "new" ? null : editing} onDone={() => setEditing(null)} />}
      </Modal>
    </div>
  );
}

export function PriceListScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Price list not found" /> : <PriceListDetail id={id} />)}</WithIdParam>;
}

/* ---------- Invoice ---------- */

function InvoiceDetail({ id }: { id: number }) {
  const query = useInvoice(id);
  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} />;
  const i = query.data;
  const money = (m: number) => formatMoney(m, i.currency);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Invoice ${i.invoice_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/orders/" className="hover:text-ink">Orders</Link>
            <span>/</span>
            <Link href={`/orders/view/?id=${i.order.id}`} className="hover:text-ink">Job {i.order.order_no}</Link>
            <span>/</span>
            <span className="font-semibold text-ink">Invoice</span>
          </div>
        }
        description={`${i.page?.header ?? "Official Sales Invoice"} · Job ${i.order.order_no} · ${i.order.customer} · Issued on ${formatDateTime(i.issued_at)} by ${i.issued_by ?? "System"}`}
        actions={<Badge tone={i.status === "issued" ? "success" : "danger"} dot>{i.status}</Badge>}
      />
      {i.void_reason && <FormError message={`Invoice voided: ${i.void_reason}`} />}

      {/* Official Invoice Card */}
      <div className="rounded-2xl border border-line bg-surface p-6 shadow-sm md:p-8">
        <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-line pb-6 gap-4">
          <div>
            <h2 className="text-xl font-bold text-ink">Official Tax Invoice</h2>
            <p className="font-mono text-xs text-muted mt-0.5">#{i.invoice_no}</p>
          </div>
          <div className="text-right text-xs text-muted">
            <p>Priced on <strong className="text-ink">{formatBusinessDate(i.pricing_date)}</strong></p>
            <p className="text-[11px] text-muted">Immutable snapshot: rates are fixed permanently</p>
          </div>
        </div>

        {/* Lines Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs font-semibold uppercase tracking-wider text-muted">
              <tr>
                <th className="py-3 px-2">Description</th>
                <th className="py-3 px-2 text-right">Qty</th>
                <th className="py-3 px-2 text-right">Unit Price</th>
                <th className="py-3 px-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line/70">
              {(i.lines ?? []).map((l, n) => (
                <tr key={n}>
                  <td className="py-3 px-2 font-medium text-ink">{l.description}</td>
                  <td className="py-3 px-2 text-right font-semibold">{l.quantity}</td>
                  <td className="py-3 px-2 text-right text-muted">{money(l.unit_price_minor)}</td>
                  <td className="py-3 px-2 text-right font-semibold text-ink">{money(l.line_total_minor)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t-2 border-line text-sm">
              <tr>
                <td colSpan={3} className="pt-3 pb-1 text-right text-muted">Subtotal</td>
                <td className="pt-3 pb-1 text-right font-semibold text-ink">{money(i.subtotal_minor)}</td>
              </tr>
              {i.discount_minor > 0 && (
                <tr>
                  <td colSpan={3} className="py-1 text-right text-muted">Discount</td>
                  <td className="py-1 text-right font-semibold text-danger">− {money(i.discount_minor)}</td>
                </tr>
              )}
              {i.tax_minor > 0 && (
                <tr>
                  <td colSpan={3} className="py-1 text-right text-muted">Tax ({i.tax_percent}%)</td>
                  <td className="py-1 text-right font-semibold text-ink">{money(i.tax_minor)}</td>
                </tr>
              )}
              <tr>
                <td colSpan={3} className="py-1 text-right text-muted">Delivery Charge</td>
                <td className="py-1 text-right font-semibold text-ink">{money(i.delivery_fee_minor)}</td>
              </tr>
              <tr className="border-t border-line">
                <td colSpan={3} className="pt-3 text-right text-base font-bold text-ink">Grand Total</td>
                <td className="pt-3 text-right text-xl font-extrabold text-brand">{money(i.total_minor)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      {i.payments && (
        <Section title="Payment & Advance Status" description="Advance requirement verification against issued invoice totals.">
          <div className="grid gap-3 sm:grid-cols-4">
            <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/50 p-4">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Required Advance ({i.payments.advance_percent}%)</span>
              <span className="text-base font-bold text-ink">{money(i.payments.advance_required_minor)}</span>
            </div>
            <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/50 p-4">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Confirmed Payments</span>
              <span className="text-base font-bold text-success-strong">{money(i.payments.verified_paid_minor)}</span>
            </div>
            <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/50 p-4">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Advance Shortfall</span>
              <span className={i.payments.advance_shortfall_minor > 0 ? "text-base font-bold text-danger" : "text-base font-bold text-success-strong"}>
                {i.payments.advance_shortfall_minor > 0 ? money(i.payments.advance_shortfall_minor) : "Fully Settled"}
              </span>
            </div>
            <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/50 p-4">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Remaining Balance</span>
              <span className="text-base font-bold text-ink">{money(i.payments.balance_minor)}</span>
            </div>
          </div>
        </Section>
      )}
    </div>
  );
}

export function InvoiceScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Invoice not found" /> : <InvoiceDetail id={id} />)}</WithIdParam>;
}
