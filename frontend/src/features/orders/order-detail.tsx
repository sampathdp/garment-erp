"use client";

import Link from "next/link";
import { useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { AlertTriangleIcon, CheckCircleIcon, ClockIcon, CreditCardIcon, FileTextIcon, PlusIcon, UserIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { PAYMENT_STATUS_LABELS as PAY_LABELS, useOrderPayments } from "@/features/payments/api";
import { RecordPaymentForm } from "@/features/payments/record-payment-form";
import { ApiError } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime, formatMoney, minorToInput, parseMoneyToMinor } from "@/lib/format";
import { type Order, PAYMENT_STATUS_LABELS, type UrgentSlot, useOrder, useOrderMutations, useOrderTypes, useProductCategories } from "./api";
import { OrderStatusBadge } from "./orders-screen";
import { JobDetailsPanel, ProductionPanel } from "./production-panels";

const errorText = (error: unknown) => (error instanceof ApiError ? error.message : error ? "Something went wrong. Please try again." : null);

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1 rounded-lg bg-subtle/40 p-3 border border-line/50">
      <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-ink break-words">{children}</dd>
    </div>
  );
}

function EditSpecForm({ order, onDone }: { order: Order; onDone: () => void }) {
  const { update } = useOrderMutations(order.id);
  const types = useOrderTypes();
  const categories = useProductCategories();
  const { notify } = useToast();
  const [v, setV] = useState({
    order_type_id: String(order.order_type?.id ?? ""),
    due_date: order.due_date,
    product_type_id: String(order.product_category?.id ?? ""),
    gross_quantity: String(order.gross_quantity),
    unit_price: minorToInput(order.pricing.unit_price_minor ?? 0),
    delivery_fee: minorToInput(order.pricing.delivery_fee_minor),
    discount: minorToInput(order.pricing.discount_minor),
  });
  const fieldErrors = update.error instanceof ApiError ? update.error.fieldErrors : {};
  const opt = (items: { id: number; name: string }[] | undefined) => (items ?? []).map((i) => ({ value: String(i.id), label: i.name }));

  const save = () =>
    update.mutate(
      {
        version: order.version,
        order_type_id: Number(v.order_type_id),
        due_date: v.due_date,
        product_type_id: Number(v.product_type_id),
        gross_quantity: Number(v.gross_quantity),
        unit_price_minor: parseMoneyToMinor(v.unit_price) ?? -1,
        delivery_fee_minor: parseMoneyToMinor(v.delivery_fee) ?? -1,
        discount_minor: parseMoneyToMinor(v.discount) ?? -1,
      },
      { onSuccess: () => (notify("Job specification saved", "success"), onDone()) },
    );

  return (
    <form noValidate className="flex flex-col gap-4" onSubmit={(e) => (e.preventDefault(), save())}>
      <FormError message={update.error instanceof ApiError && !update.error.isValidation ? update.error.message : null} />
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label="Order type" options={opt(types.data)} value={v.order_type_id} error={fieldErrors.order_type_id?.[0]} onChange={(e) => setV({ ...v, order_type_id: e.target.value })} />
        <TextField label="Due date" type="date" value={v.due_date} error={fieldErrors.due_date?.[0]} hint="Changing it re-checks the seven-day rule." onChange={(e) => setV({ ...v, due_date: e.target.value })} />
        <SelectField label="Product / item category" options={opt(categories.data)} value={v.product_type_id} error={fieldErrors.product_type_id?.[0]} onChange={(e) => setV({ ...v, product_type_id: e.target.value })} />
        <TextField label="Gross quantity" inputMode="numeric" value={v.gross_quantity} error={fieldErrors.gross_quantity?.[0]} onChange={(e) => setV({ ...v, gross_quantity: e.target.value })} />
        <TextField label="Unit price" inputMode="decimal" value={v.unit_price} error={fieldErrors.unit_price_minor?.[0]} onChange={(e) => setV({ ...v, unit_price: e.target.value })} />
        <TextField label="Delivery fee" inputMode="decimal" value={v.delivery_fee} error={fieldErrors.delivery_fee_minor?.[0]} onChange={(e) => setV({ ...v, delivery_fee: e.target.value })} />
        <TextField label="Discount" inputMode="decimal" value={v.discount} error={fieldErrors.discount_minor?.[0]} onChange={(e) => setV({ ...v, discount: e.target.value })} />
      </div>
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" loading={update.isPending}>
          Save changes
        </Button>
      </div>
    </form>
  );
}

function UrgentPanel({ order }: { order: Order }) {
  const { decideUrgent } = useOrderMutations(order.id);
  const { notify } = useToast();
  const [rejecting, setRejecting] = useState<UrgentSlot | null>(null);
  const [reason, setReason] = useState("");
  const approval = order.urgent_approval;
  if (!order.urgent.is_urgent || !approval) return null;

  const canDecide = (slot: UrgentSlot) => (slot === "second_admin" ? order.abilities?.decide_urgent_second_admin : order.abilities?.decide_urgent_operation_admin) ?? false;
  const decide = (slot: UrgentSlot, decision: "approved" | "rejected") =>
    decideUrgent.mutate(
      { slot, decision, reason: decision === "rejected" ? reason : null },
      { onSuccess: () => (notify(decision === "approved" ? "Approval recorded" : "Urgent job rejected", "success"), setRejecting(null), setReason("")) },
    );

  return (
    <Section
      title="Urgent Order Approval"
      className="border-danger/30 bg-danger-soft/20"
      description={`Due in ${approval.days_available} day(s) from ${formatBusinessDate(approval.reference_date)} (fewer than 7 working days). Dual sign-off required from Second Admin and Operation Admin.`}
    >
      <FormError message={errorText(decideUrgent.error)} />
      <div className="grid gap-4 sm:grid-cols-2 mt-2">
        {approval.slots.map((s) => (
          <div key={s.slot} className="flex flex-col justify-between gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-sm font-bold text-ink">{s.label}</span>
              <Badge tone={s.decision === "approved" ? "success" : s.decision === "rejected" ? "danger" : "warning"} dot>
                {s.decision === "approved" ? "Approved" : s.decision === "rejected" ? "Rejected" : "Pending Sign-off"}
              </Badge>
            </div>
            {s.decided_by && (
              <p className="text-xs text-muted leading-relaxed">
                By <strong className="font-semibold text-ink">{s.decided_by.name}</strong> on {formatDateTime(s.decided_at)}
                {s.reason ? ` · "${s.reason}"` : ""}
              </p>
            )}
            {canDecide(s.slot) && (
              <div className="flex gap-2 pt-2 border-t border-line/60">
                <Button size="sm" loading={decideUrgent.isPending} onClick={() => decide(s.slot, "approved")}>
                  Approve
                </Button>
                <Button size="sm" variant="danger" onClick={() => setRejecting(s.slot)}>
                  Reject
                </Button>
              </div>
            )}
          </div>
        ))}
      </div>
      <Modal open={rejecting !== null} title="Reject Urgent Order" onClose={() => setRejecting(null)}>
        <div className="flex flex-col gap-4">
          <TextAreaField label="Reason for rejection" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRejecting(null)}>
              Cancel
            </Button>
            <Button variant="danger" disabled={reason.trim().length < 3} loading={decideUrgent.isPending} onClick={() => rejecting && decide(rejecting, "rejected")}>
              Confirm Rejection
            </Button>
          </div>
        </div>
      </Modal>
    </Section>
  );
}

function PaymentsPanel({ order }: { order: Order }) {
  const payments = useOrderPayments(order.id, order.abilities?.view_payments ?? false);
  const [recording, setRecording] = useState(false);
  const a = order.advance;

  return (
    <Section
      title="Financial & Advance Tracking"
      description="30% advance rule: Verified deposit is required before the order can be released to production."
      actions={
        order.abilities?.record_payment ? (
          <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => setRecording(true)}>
            Record payment
          </Button>
        ) : undefined
      }
    >
      <div className="mb-6 grid gap-3 sm:grid-cols-4">
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/40 p-4">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Invoice Total</span>
          <span className="text-lg font-bold text-ink">{formatMoney(order.pricing.total_minor, order.currency)}</span>
        </div>
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/40 p-4">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">30% Advance Required</span>
          <span className="text-lg font-bold text-ink">{formatMoney(a.required_minor, order.currency)}</span>
        </div>
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/40 p-4">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Verified Payments</span>
          <span className="text-lg font-bold text-success-strong">{formatMoney(a.verified_minor, order.currency)}</span>
        </div>
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-subtle/40 p-4">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Advance Shortfall</span>
          <span className={a.shortfall_minor > 0 ? "text-lg font-bold text-danger" : "text-lg font-bold text-success-strong"}>
            {a.shortfall_minor > 0 ? formatMoney(a.shortfall_minor, order.currency) : "Fully Covered"}
          </span>
        </div>
      </div>

      <div className="mb-4 flex items-center gap-2 rounded-lg bg-subtle/60 p-3 text-xs">
        <span className="font-semibold text-ink">Production Eligibility:</span>
        <Badge tone={order.production_eligible ? "success" : "danger"} dot>
          {order.production_eligible ? "Eligible for production" : a.meets_requirement ? "Awaiting urgent approval" : "Blocked — advance deposit below 30%"}
        </Badge>
      </div>

      {payments.isPending && order.abilities?.view_payments ? (
        <LoadingState />
      ) : payments.error ? (
        <ErrorState error={payments.error} />
      ) : (payments.data ?? []).length === 0 ? (
        <EmptyState title="No payments recorded" description="No customer payments have been submitted for this order yet." />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
          <table className="w-full min-w-[32rem] text-left text-sm">
            <thead className="border-b border-line bg-subtle/60 text-xs font-semibold uppercase tracking-wider text-muted">
              <tr>
                <th className="px-4 py-3">Date</th>
                <th className="px-4 py-3">Amount</th>
                <th className="px-4 py-3">Method</th>
                <th className="px-4 py-3">Reference</th>
                <th className="px-4 py-3">Verification Status</th>
                <th className="px-4 py-3">Submitted by</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line/70">
              {(payments.data ?? []).map((p) => (
                <tr key={p.id} className="hover:bg-subtle/50 transition">
                  <td className="px-4 py-3 font-medium">
                    <Link className="text-brand hover:underline" href={`/payments/view/?id=${p.id}`}>
                      {formatBusinessDate(p.payment_date)}
                    </Link>
                  </td>
                  <td className="px-4 py-3 font-semibold text-ink">{formatMoney(p.amount_minor, p.currency)}</td>
                  <td className="px-4 py-3 capitalize text-muted">{p.method.replace("_", " ")}</td>
                  <td className="px-4 py-3 font-mono text-xs text-muted">{p.reference_no ?? "—"}</td>
                  <td className="px-4 py-3">
                    <Badge tone={p.status === "verified" ? "success" : p.status === "rejected" ? "danger" : "brand"} dot>
                      {PAY_LABELS[p.status]}
                    </Badge>
                    {p.rejection_reason && <span className="block mt-0.5 text-xs text-danger">{p.rejection_reason}</span>}
                  </td>
                  <td className="px-4 py-3 text-xs text-muted">{p.recorded_by?.name ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Modal open={recording} title={`Record Payment — ${order.order_no}`} onClose={() => setRecording(false)}>
        {recording && <RecordPaymentForm orderId={order.id} currency={order.currency} suggestedMinor={a.shortfall_minor} onDone={() => setRecording(false)} />}
      </Modal>
    </Section>
  );
}

function OrderDetail({ id }: { id: number }) {
  const query = useOrder(id);
  const { submit } = useOrderMutations(id);
  const { notify } = useToast();
  const [editing, setEditing] = useState(false);

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const order = query.data;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Job ${order.order_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/orders/" className="hover:text-ink">Orders</Link>
            <span>/</span>
            <span className="font-semibold text-ink">Job {order.order_no}</span>
          </div>
        }
        description={`${order.page.name} (${order.page.code}) · Branch ${order.branch.name} (${order.branch.code}) · Created on ${formatDateTime(order.created_at)}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <OrderStatusBadge order={order} />
            {order.abilities?.update && (
              <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>
                Edit job
              </Button>
            )}
            {order.abilities?.submit && (
              <Button size="sm" loading={submit.isPending} onClick={() => submit.mutate(order.version, { onSuccess: (o) => notify(`Submitted: ${o.status.label}`, "success") })}>
                Submit order
              </Button>
            )}
          </div>
        }
      />
      <FormError message={errorText(submit.error)} />

      {/* Customer Information */}
      <Section
        title="Customer Information"
        actions={
          <Link href={`/customers/view/?id=${order.customer.id}`} className="text-xs font-semibold text-brand hover:underline">
            View full profile →
          </Link>
        }
      >
        <dl className="grid gap-3 sm:grid-cols-3">
          <Detail label="Customer Name">
            <Link className="text-brand hover:underline" href={`/customers/view/?id=${order.customer.id}`}>
              {order.customer.name}
            </Link>{" "}
            <span className="font-normal text-muted">({order.customer.customer_code})</span>
          </Detail>
          <Detail label="Primary Phone">{order.customer.phone}</Detail>
          <Detail label="WhatsApp">{order.customer.whatsapp ?? "—"}</Detail>
          <Detail label="Secondary Phone">{order.customer.other_phone ?? "—"}</Detail>
          <Detail label="Delivery Address">{order.customer.address ?? "—"}</Detail>
        </dl>
      </Section>

      {/* Job Specifications */}
      <Section title="Job Specifications">
        <dl className="grid gap-3 sm:grid-cols-4">
          <Detail label="Order Type">{order.order_type?.name ?? "—"}</Detail>
          <Detail label="Due Date">
            <div className="flex items-center gap-1.5">
              <span>{formatBusinessDate(order.due_date)}</span>
              {order.urgent.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}
            </div>
          </Detail>
          <Detail label="Item Category">{order.product_category?.name ?? "—"}</Detail>
          <Detail label="Gross Quantity">{order.gross_quantity} units</Detail>
          <Detail label="Unit Price">{formatMoney(order.pricing.unit_price_minor ?? 0, order.currency)}</Detail>
          <Detail label="Delivery Fee">{formatMoney(order.pricing.delivery_fee_minor, order.currency)}</Detail>
          <Detail label="Discount">{formatMoney(order.pricing.discount_minor, order.currency)}</Detail>
          <Detail label="Payment Status">{PAYMENT_STATUS_LABELS[order.payment_status]}</Detail>
        </dl>
      </Section>

      <UrgentPanel order={order} />
      <JobDetailsPanel key={`details-${order.version}`} order={order} />
      <ProductionPanel order={order} />
      {order.status.value !== "draft" && <PaymentsPanel order={order} />}

      {/* Status History Timeline */}
      {order.history && order.history.length > 0 && (
        <Section title="Status Audit Timeline" description="Complete record of all status transitions and staff sign-offs.">
          <ol className="relative ml-3 border-l border-line/70 space-y-4 text-xs">
            {order.history.map((h, i) => (
              <li key={i} className="ml-4">
                <span className="absolute -left-1.5 mt-1.5 size-3 rounded-full border-2 border-surface bg-brand" />
                <div className="flex flex-wrap items-center gap-x-2">
                  <span className="font-semibold text-ink">{h.from ? `${h.from} → ${h.to}` : h.to}</span>
                  <span className="text-muted">· {formatDateTime(h.at)}</span>
                  {h.actor && <span className="rounded bg-subtle px-1.5 py-0.5 text-muted">by {h.actor}</span>}
                </div>
                {h.reason && <p className="mt-1 text-muted italic">"{h.reason}"</p>}
              </li>
            ))}
          </ol>
        </Section>
      )}

      <Modal open={editing} title="Edit Job Specification" onClose={() => setEditing(false)}>
        {editing && <EditSpecForm order={order} onDone={() => setEditing(false)} />}
      </Modal>
    </div>
  );
}

export function OrderScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Order not found" /> : <OrderDetail id={id} />)}</WithIdParam>;
}
