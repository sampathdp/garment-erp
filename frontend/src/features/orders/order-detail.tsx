"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import {
  Badge,
  FormError,
  PageHeader,
  Section,
  SelectField,
  TextAreaField,
} from "@/components/ui/form-controls";
import {
  AlertTriangleIcon,
  BoxesIcon,
  CalendarIcon,
  CheckCircleIcon,
  CheckIcon,
  ChevronRightIcon,
  ClockIcon,
  CopyIcon,
  CreditCardIcon,
  ExternalLinkIcon,
  FileTextIcon,
  LayersIcon,
  PencilIcon,
  PhoneIcon,
  PlusIcon,
  ScissorsIcon,
  ShirtIcon,
  SparklesIcon,
  UserIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { PAYMENT_STATUS_LABELS as PAY_LABELS, useOrderPayments } from "@/features/payments/api";
import { RecordPaymentForm } from "@/features/payments/record-payment-form";
import { ApiError } from "@/lib/api-client";
import {
  formatBusinessDate,
  formatDateTime,
  formatMoney,
  minorToInput,
  parseMoneyToMinor,
} from "@/lib/format";
import {
  type Order,
  PAYMENT_STATUS_LABELS,
  type UrgentSlot,
  useOrder,
  useOrderMutations,
  useOrderTypes,
  useProductCategories,
} from "./api";
import { OrderStatusBadge } from "./orders-screen";
import { JobDetailsPanel, ProductionPanel } from "./production-panels";

const errorText = (error: unknown) =>
  error instanceof ApiError
    ? error.message
    : error
      ? "Something went wrong. Please try again."
      : null;

function getDaysRemaining(dueDateStr: string): { days: number; isOverdue: boolean; label: string } {
  try {
    const due = new Date(dueDateStr);
    const now = new Date();
    due.setHours(0, 0, 0, 0);
    now.setHours(0, 0, 0, 0);
    const diffTime = due.getTime() - now.getTime();
    const days = Math.round(diffTime / (1000 * 60 * 60 * 24));
    if (days < 0) {
      return {
        days: Math.abs(days),
        isOverdue: true,
        label: `${Math.abs(days)} day${Math.abs(days) === 1 ? "" : "s"} overdue`,
      };
    }
    if (days === 0) return { days: 0, isOverdue: false, label: "Due today" };
    return { days, isOverdue: false, label: `Due in ${days} day${days === 1 ? "" : "s"}` };
  } catch {
    return { days: 0, isOverdue: false, label: dueDateStr };
  }
}

function Detail({ label, children, icon: Icon }: { label: string; children: ReactNode; icon?: typeof ShirtIcon }) {
  return (
    <div className="flex flex-col gap-1 rounded-xl bg-subtle/30 p-3.5 border border-line/60 shadow-2xs">
      <div className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-muted">
        {Icon && <Icon size={13} className="text-muted/70" />}
        <span>{label}</span>
      </div>
      <div className="text-sm font-semibold text-ink break-words mt-0.5">{children}</div>
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
  const opt = (items: { id: number; name: string }[] | undefined) =>
    (items ?? []).map((i) => ({ value: String(i.id), label: i.name }));

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
      <FormError
        message={
          update.error instanceof ApiError && !update.error.isValidation
            ? update.error.message
            : null
        }
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField
          label="Order type"
          options={opt(types.data)}
          value={v.order_type_id}
          error={fieldErrors.order_type_id?.[0]}
          onChange={(e) => setV({ ...v, order_type_id: e.target.value })}
        />
        <TextField
          label="Due date"
          type="date"
          value={v.due_date}
          error={fieldErrors.due_date?.[0]}
          hint="Changing it re-checks the seven-day rule."
          onChange={(e) => setV({ ...v, due_date: e.target.value })}
        />
        <SelectField
          label="Product / item category"
          options={opt(categories.data)}
          value={v.product_type_id}
          error={fieldErrors.product_type_id?.[0]}
          onChange={(e) => setV({ ...v, product_type_id: e.target.value })}
        />
        <TextField
          label="Gross quantity"
          inputMode="numeric"
          value={v.gross_quantity}
          error={fieldErrors.gross_quantity?.[0]}
          onChange={(e) => setV({ ...v, gross_quantity: e.target.value })}
        />
        <TextField
          label="Unit price"
          inputMode="decimal"
          value={v.unit_price}
          error={fieldErrors.unit_price_minor?.[0]}
          onChange={(e) => setV({ ...v, unit_price: e.target.value })}
        />
        <TextField
          label="Delivery fee"
          inputMode="decimal"
          value={v.delivery_fee}
          error={fieldErrors.delivery_fee_minor?.[0]}
          onChange={(e) => setV({ ...v, delivery_fee: e.target.value })}
        />
        <TextField
          label="Discount"
          inputMode="decimal"
          value={v.discount}
          error={fieldErrors.discount_minor?.[0]}
          onChange={(e) => setV({ ...v, discount: e.target.value })}
        />
      </div>
      <div className="flex justify-end gap-2.5 pt-2 border-t border-line">
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

  const canDecide = (slot: UrgentSlot) =>
    (slot === "second_admin"
      ? order.abilities?.decide_urgent_second_admin
      : order.abilities?.decide_urgent_operation_admin) ?? false;
  const decide = (slot: UrgentSlot, decision: "approved" | "rejected") =>
    decideUrgent.mutate(
      { slot, decision, reason: decision === "rejected" ? reason : null },
      {
        onSuccess: () => (
          notify(decision === "approved" ? "Approval recorded" : "Urgent job rejected", "success"),
          setRejecting(null),
          setReason("")
        ),
      },
    );

  return (
    <div className="rounded-2xl border-2 border-danger/30 bg-danger-soft/20 p-5 shadow-xs">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-danger/20 pb-3">
        <div className="flex items-center gap-2.5">
          <div className="flex size-9 items-center justify-center rounded-xl bg-danger/10 text-danger font-bold">
            <AlertTriangleIcon size={18} />
          </div>
          <div>
            <h3 className="text-base font-bold text-ink">Urgent Job Dual Sign-off Required</h3>
            <p className="text-xs text-muted leading-relaxed">
              Due in {approval.days_available} day(s) from {formatBusinessDate(approval.reference_date)} (fewer than 7 working days). Dual sign-off required from Second Admin and Operation Admin before release.
            </p>
          </div>
        </div>
        <Badge tone="danger" dot>Dual Sign-off Required</Badge>
      </div>

      <FormError message={errorText(decideUrgent.error)} />
      <div className="grid gap-4 sm:grid-cols-2 mt-4">
        {approval.slots.map((s) => (
          <div
            key={s.slot}
            className="flex flex-col justify-between gap-3 rounded-xl border border-line bg-surface p-4 shadow-2xs"
          >
            <div className="flex items-center justify-between">
              <span className="text-sm font-bold text-ink">{s.label}</span>
              <Badge
                tone={
                  s.decision === "approved"
                    ? "success"
                    : s.decision === "rejected"
                      ? "danger"
                      : "warning"
                }
                dot
              >
                {s.decision === "approved"
                  ? "Approved"
                  : s.decision === "rejected"
                    ? "Rejected"
                    : "Pending Sign-off"}
              </Badge>
            </div>
            {s.decided_by && (
              <p className="text-xs text-muted leading-relaxed">
                By <strong className="font-semibold text-ink">{s.decided_by.name}</strong> on{" "}
                {formatDateTime(s.decided_at)}
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
          <TextAreaField
            label="Reason for rejection"
            maxLength={500}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRejecting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              disabled={reason.trim().length < 3}
              loading={decideUrgent.isPending}
              onClick={() => rejecting && decide(rejecting, "rejected")}
            >
              Confirm Rejection
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

function PaymentsPanel({
  order,
  onRecordClick,
}: {
  order: Order;
  onRecordClick: () => void;
}) {
  const payments = useOrderPayments(order.id, order.abilities?.view_payments ?? false);
  const a = order.advance;
  const depositPercent =
    a.required_minor > 0
      ? Math.min(100, Math.round((a.verified_minor / a.required_minor) * 100))
      : 100;

  return (
    <div className="flex flex-col gap-5">
      {/* 4 Financial KPI Stat Cards */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="flex flex-col gap-1 rounded-2xl border border-line bg-surface p-4 shadow-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
            Invoice Total
          </span>
          <span className="text-xl font-extrabold text-ink tracking-tight">
            {formatMoney(order.pricing.total_minor, order.currency)}
          </span>
          <span className="text-xs text-muted">
            {PAYMENT_STATUS_LABELS[order.payment_status]}
          </span>
        </div>

        <div className="flex flex-col gap-1 rounded-2xl border border-line bg-surface p-4 shadow-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
            30% Advance Deposit Required
          </span>
          <span className="text-xl font-extrabold text-ink tracking-tight">
            {formatMoney(a.required_minor, order.currency)}
          </span>
          <span className="text-xs text-muted">Required prior to production</span>
        </div>

        <div className="flex flex-col gap-1 rounded-2xl border border-line bg-surface p-4 shadow-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
            Verified Payments
          </span>
          <span className="text-xl font-extrabold text-emerald-500 tracking-tight">
            {formatMoney(a.verified_minor, order.currency)}
          </span>
          <span className="text-xs text-muted">Confirmed by Second Admin</span>
        </div>

        <div
          className={`flex flex-col gap-1 rounded-2xl border p-4 shadow-xs ${
            a.shortfall_minor > 0
              ? "border-amber-500/30 bg-amber-500/5 text-amber-500"
              : "border-line bg-surface text-emerald-500"
          }`}
        >
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
            Advance Shortfall
          </span>
          <span
            className={`text-xl font-extrabold tracking-tight ${
              a.shortfall_minor > 0 ? "text-amber-500" : "text-emerald-500"
            }`}
          >
            {a.shortfall_minor > 0 ? formatMoney(a.shortfall_minor, order.currency) : "Fully Covered"}
          </span>
          <span className="text-xs text-muted">
            {a.shortfall_minor > 0 ? "Deposit shortfall remaining" : "30% deposit requirement met"}
          </span>
        </div>
      </div>

      {/* Advance Progress Bar & Release Eligibility Box */}
      <div className="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5 shadow-xs">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <span className="text-xs font-bold text-ink">Advance Deposit Progress:</span>
            <span className="text-xs font-semibold text-muted">
              {formatMoney(a.verified_minor, order.currency)} of {formatMoney(a.required_minor, order.currency)} ({depositPercent}%)
            </span>
          </div>

          <div className="flex items-center gap-2">
            <span className="text-xs text-muted font-medium">Production Status:</span>
            <Badge tone={order.production_eligible ? "success" : "danger"} dot>
              {order.production_eligible
                ? "Eligible for production release"
                : a.meets_requirement
                  ? "Awaiting dual urgent approval"
                  : "Blocked — advance deposit below 30%"}
            </Badge>
          </div>
        </div>

        {/* Progress Track */}
        <div className="h-2.5 w-full overflow-hidden rounded-full bg-subtle">
          <div
            className={`h-full transition-all duration-300 rounded-full ${
              a.meets_requirement ? "bg-emerald-500" : "bg-amber-500"
            }`}
            style={{ width: `${depositPercent}%` }}
          />
        </div>
      </div>

      {/* Advance Shortfall Callout if applicable */}
      {!a.meets_requirement && a.verified_minor > 0 && (
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs shadow-xs">
          <div className="flex items-start gap-3">
            <AlertTriangleIcon size={18} className="text-amber-500 shrink-0 mt-0.5" />
            <div className="flex flex-col gap-0.5">
              <span className="font-bold text-ink text-sm">
                Partial Advance Received — {formatMoney(a.verified_minor, order.currency)} of{" "}
                {formatMoney(a.required_minor, order.currency)} (30%)
              </span>
              <p className="text-muted leading-relaxed">
                The Second Admin confirmed payment of{" "}
                <strong className="text-ink font-semibold">
                  {formatMoney(a.verified_minor, order.currency)}
                </strong>
                . However, the order requires the full 30% deposit ({formatMoney(a.required_minor, order.currency)}) before release.
              </p>
              <p className="font-semibold text-amber-500">
                Remaining advance shortfall: {formatMoney(a.shortfall_minor, order.currency)}.
              </p>
            </div>
          </div>

          {order.abilities?.record_payment && (
            <Button
              size="sm"
              icon={<PlusIcon size={13} />}
              onClick={onRecordClick}
              className="shrink-0 text-xs font-semibold"
            >
              Record remaining deposit
            </Button>
          )}
        </div>
      )}

      {/* Payment History Table */}
      <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col gap-4">
        <div className="flex items-center justify-between gap-3 border-b border-line pb-3">
          <div>
            <h3 className="text-sm font-bold text-ink">Payment History & Receipts</h3>
            <p className="text-xs text-muted">
              All client payment records, bank slip uploads, and Second Admin verification states.
            </p>
          </div>

          {order.abilities?.record_payment && (
            <Button
              size="sm"
              variant="outline"
              icon={<PlusIcon size={14} />}
              onClick={onRecordClick}
              className="text-xs font-semibold border-brand/30 text-brand hover:bg-brand/10"
            >
              + Record payment
            </Button>
          )}
        </div>

        {payments.isPending && order.abilities?.view_payments ? (
          <LoadingState label="Loading payment records…" />
        ) : payments.error ? (
          <ErrorState error={payments.error} />
        ) : (payments.data ?? []).length === 0 ? (
          <EmptyState
            title="No payments recorded"
            description="No customer payments have been submitted for this order yet."
          />
        ) : (
          <div className="overflow-hidden rounded-xl border border-line bg-surface">
            <table className="w-full text-left text-xs">
              <thead className="border-b border-line bg-subtle/50 text-[11px] font-bold uppercase tracking-wider text-muted">
                <tr>
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Amount</th>
                  <th className="px-4 py-3">Method</th>
                  <th className="px-4 py-3">Reference</th>
                  <th className="px-4 py-3">Verification Status</th>
                  <th className="px-4 py-3">Submitted by</th>
                  <th className="px-4 py-3 text-right">Receipt</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line/70">
                {(payments.data ?? []).map((p) => (
                  <tr key={p.id} className="hover:bg-subtle/30 transition">
                    <td className="px-4 py-3 font-semibold text-ink">
                      {formatBusinessDate(p.payment_date)}
                    </td>
                    <td className="px-4 py-3 font-bold text-sm text-ink">
                      {formatMoney(p.amount_minor, p.currency)}
                    </td>
                    <td className="px-4 py-3 capitalize text-muted">
                      <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-medium text-ink">
                        {p.method.replace("_", " ")}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-muted">
                      {p.reference_no ?? "—"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge
                        tone={
                          p.status === "verified"
                            ? "success"
                            : p.status === "rejected"
                              ? "danger"
                              : "brand"
                        }
                        dot
                      >
                        {PAY_LABELS[p.status]}
                      </Badge>
                      {p.rejection_reason && (
                        <span className="block mt-0.5 text-xs text-danger">
                          {p.rejection_reason}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted">
                      {p.recorded_by?.name ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Link
                        className="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline"
                        href={`/payments/view/?id=${p.id}`}
                      >
                        <span>View slip</span>
                        <ChevronRightIcon size={12} />
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

function OrderDetail({ id }: { id: number }) {
  const query = useOrder(id);
  const { submit } = useOrderMutations(id);
  const { notify } = useToast();
  const searchParams = useSearchParams();

  type OrderTab = "overview" | "payments" | "production" | "history";
  const urlTab = searchParams.get("tab") as OrderTab | null;
  const initialTab: OrderTab =
    urlTab && ["overview", "payments", "production", "history"].includes(urlTab)
      ? urlTab
      : "overview";

  const [activeTab, setActiveTab] = useState<OrderTab>(initialTab);
  const [editing, setEditing] = useState(false);
  const [recording, setRecording] = useState(false);
  const [copied, setCopied] = useState(false);

  if (query.isPending) return <LoadingState label="Loading job details…" />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const order = query.data;

  const handleCopyJobNo = () => {
    navigator.clipboard.writeText(order.order_no);
    setCopied(true);
    notify(`Job number ${order.order_no} copied to clipboard`, "info");
    setTimeout(() => setCopied(false), 2000);
  };

  const dueCountdown = getDaysRemaining(order.due_date);
  const a = order.advance;
  const depositPercent =
    a.required_minor > 0
      ? Math.min(100, Math.round((a.verified_minor / a.required_minor) * 100))
      : 100;

  return (
    <div className="flex flex-col gap-6">
      {/* Executive Command Header */}
      <div className="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6 shadow-xs">
        {/* Breadcrumb + Metadata */}
        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
          <div className="flex items-center gap-2">
            <Link
              href="/orders/"
              className="font-medium text-brand hover:underline inline-flex items-center gap-1"
            >
              ← Back to Orders
            </Link>
            <span>/</span>
            <span className="font-semibold text-ink font-mono">{order.order_no}</span>
          </div>

          <div className="flex items-center gap-2">
            <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
              Company / Shop: {order.page.name}
            </span>
          </div>
        </div>

        {/* Main Title Row & Actions */}
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-line pb-5">
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-2xl font-extrabold tracking-tight text-ink font-mono">
                {order.order_no}
              </h1>

              <button
                type="button"
                onClick={handleCopyJobNo}
                title="Copy Job Number"
                className="inline-flex items-center gap-1 rounded-lg border border-line bg-subtle/50 px-2.5 py-1 text-xs font-medium text-muted hover:text-ink hover:bg-subtle transition cursor-pointer"
              >
                {copied ? (
                  <>
                    <CheckIcon size={12} className="text-emerald-500" />
                    <span className="text-emerald-500 font-semibold">Copied</span>
                  </>
                ) : (
                  <>
                    <CopyIcon size={12} />
                    <span>Copy</span>
                  </>
                )}
              </button>

              <OrderStatusBadge order={order} />

              {order.urgent.is_urgent && (
                <Badge tone="danger" dot>
                  Urgent Order
                </Badge>
              )}

              <Badge tone={order.production_eligible ? "success" : "neutral"} dot>
                {order.production_eligible ? "Production Eligible" : "Pre-Production"}
              </Badge>
            </div>

            <p className="text-xs text-muted">
              Client:{" "}
              <Link
                href={`/customers/view/?id=${order.customer.id}`}
                className="font-bold text-ink hover:text-brand hover:underline"
              >
                {order.customer.name}
              </Link>{" "}
              ({order.customer.customer_code}) · Created on {formatDateTime(order.created_at)}
            </p>
          </div>

          {/* Header Action Buttons */}
          <div className="flex flex-wrap items-center gap-2">
            {order.abilities?.record_payment && (
              <Button
                size="sm"
                icon={<CreditCardIcon size={14} />}
                onClick={() => setRecording(true)}
                className={
                  !a.meets_requirement && order.status.value !== "draft"
                    ? "bg-brand text-white shadow-xs"
                    : "border-brand/30 text-brand hover:bg-brand/10"
                }
                variant={
                  !a.meets_requirement && order.status.value !== "draft" ? "primary" : "outline"
                }
              >
                Record payment
              </Button>
            )}

            {order.abilities?.update && (
              <Button
                variant="secondary"
                size="sm"
                icon={<PencilIcon size={13} />}
                onClick={() => setEditing(true)}
              >
                Edit job
              </Button>
            )}

            {order.abilities?.submit && (
              <Button
                size="sm"
                loading={submit.isPending}
                onClick={() =>
                  submit.mutate(order.version, {
                    onSuccess: (o) => notify(`Submitted: ${o.status.label}`, "success"),
                  })
                }
              >
                Submit order
              </Button>
            )}
          </div>
        </div>

        {/* Executive Ribbon (4 Stat Highlights) */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {/* Stat 1: Due Date */}
          <div className="flex items-center gap-3.5 rounded-xl border border-line bg-subtle/30 p-3.5 shadow-2xs">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
              <CalendarIcon size={18} />
            </div>
            <div className="min-w-0">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Due Date
              </span>
              <p className="text-sm font-bold text-ink truncate">
                {formatBusinessDate(order.due_date)}
              </p>
              <span
                className={`text-xs font-semibold ${
                  dueCountdown.isOverdue ? "text-danger" : "text-brand"
                }`}
              >
                {dueCountdown.label}
              </span>
            </div>
          </div>

          {/* Stat 2: Quantity & Category */}
          <div className="flex items-center gap-3.5 rounded-xl border border-line bg-subtle/30 p-3.5 shadow-2xs">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-purple-500/10 text-purple-500">
              <ShirtIcon size={18} />
            </div>
            <div className="min-w-0">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Item & Volume
              </span>
              <p className="text-sm font-bold text-ink truncate">
                {order.gross_quantity} pcs · {order.product_category?.name ?? "Garment"}
              </p>
              <span className="text-xs text-muted">
                {order.order_type?.name ?? "Order"}
              </span>
            </div>
          </div>

          {/* Stat 3: Total Amount */}
          <div className="flex items-center gap-3.5 rounded-xl border border-line bg-subtle/30 p-3.5 shadow-2xs">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-500">
              <CreditCardIcon size={18} />
            </div>
            <div className="min-w-0">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Total Invoice
              </span>
              <p className="text-sm font-bold text-ink truncate">
                {formatMoney(order.pricing.total_minor, order.currency)}
              </p>
              <span className="text-xs text-muted">
                {PAYMENT_STATUS_LABELS[order.payment_status]}
              </span>
            </div>
          </div>

          {/* Stat 4: Advance Status */}
          <div className="flex items-center gap-3.5 rounded-xl border border-line bg-subtle/30 p-3.5 shadow-2xs">
            <div
              className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${
                a.meets_requirement
                  ? "bg-emerald-500/10 text-emerald-500"
                  : "bg-amber-500/10 text-amber-500"
              }`}
            >
              <CheckCircleIcon size={18} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center justify-between gap-1">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  30% Advance
                </span>
                <span className="text-xs font-bold text-ink">{depositPercent}%</span>
              </div>
              <div className="my-1 h-1.5 w-full rounded-full bg-subtle overflow-hidden">
                <div
                  className={`h-full rounded-full ${
                    a.meets_requirement ? "bg-emerald-500" : "bg-amber-500"
                  }`}
                  style={{ width: `${depositPercent}%` }}
                />
              </div>
              <span
                className={`text-[11px] font-semibold truncate block ${
                  a.shortfall_minor > 0 ? "text-amber-500" : "text-emerald-500"
                }`}
              >
                {a.shortfall_minor > 0
                  ? `Shortfall: ${formatMoney(a.shortfall_minor, order.currency)}`
                  : "30% Advance Verified"}
              </span>
            </div>
          </div>
        </div>
      </div>

      <FormError message={errorText(submit.error)} />

      {/* Urgent Dual Sign-off Banner (Placed prominently above tabs) */}
      <UrgentPanel order={order} />

      {/* Segmented Navigation Tabs */}
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2 border-b border-line pb-px">
          {(
            [
              {
                id: "overview",
                label: "Overview & Specs",
                icon: FileTextIcon,
                badge: undefined,
              },
              {
                id: "payments",
                label: "Financials & Payments",
                icon: CreditCardIcon,
                badge: !a.meets_requirement && order.status.value !== "draft" ? "Shortfall" : undefined,
                badgeTone: "danger" as const,
              },
              {
                id: "production",
                label: "Design & Manufacturing",
                icon: ShirtIcon,
                badge: order.design_task ? order.design_task.status.label : undefined,
                badgeTone: "brand" as const,
              },
              {
                id: "history",
                label: "Audit Timeline",
                icon: ClockIcon,
                badge: order.history?.length ? `${order.history.length}` : undefined,
                badgeTone: "neutral" as const,
              },
            ] as const
          ).map((tab) => {
            const Icon = tab.icon;
            const isActive = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => setActiveTab(tab.id)}
                className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition cursor-pointer ${
                  isActive
                    ? "border-brand text-brand"
                    : "border-transparent text-muted hover:border-line hover:text-ink"
                }`}
              >
                <Icon size={16} />
                <span>{tab.label}</span>
                {tab.badge && (
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                      tab.badgeTone === "danger"
                        ? "bg-danger/15 text-danger font-bold"
                        : tab.badgeTone === "brand"
                          ? "bg-brand/15 text-brand"
                          : "bg-subtle text-muted"
                    }`}
                  >
                    {tab.badge}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {/* Tab 1: Overview & Specs */}
        {activeTab === "overview" && (
          <div className="flex flex-col gap-6">
            <div className="grid gap-6 lg:grid-cols-3">
              {/* Customer Profile Card (1 col) */}
              <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col justify-between">
                <div>
                  <div className="flex items-center justify-between gap-3 border-b border-line pb-3">
                    <div className="flex items-center gap-2.5">
                      <div className="flex size-9 items-center justify-center rounded-xl bg-brand/10 text-brand">
                        <UserIcon size={18} />
                      </div>
                      <div>
                        <h3 className="text-sm font-bold text-ink">Client Details</h3>
                        <span className="font-mono text-xs text-muted">
                          {order.customer.customer_code}
                        </span>
                      </div>
                    </div>

                    <Link
                      href={`/customers/view/?id=${order.customer.id}`}
                      className="text-xs font-semibold text-brand hover:underline"
                    >
                      View profile →
                    </Link>
                  </div>

                  <div className="mt-4 flex flex-col gap-3 text-xs">
                    <div>
                      <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                        Full Name
                      </span>
                      <p className="text-sm font-bold text-ink mt-0.5">{order.customer.name}</p>
                    </div>

                    <div>
                      <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                        Primary Phone
                      </span>
                      <p className="mt-0.5">
                        <a
                          href={`tel:${order.customer.phone}`}
                          className="font-medium text-brand hover:underline inline-flex items-center gap-1"
                        >
                          <PhoneIcon size={12} />
                          {order.customer.phone}
                        </a>
                      </p>
                    </div>

                    {order.customer.whatsapp && (
                      <div>
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                          WhatsApp
                        </span>
                        <p className="mt-0.5">
                          <a
                            href={`https://wa.me/${order.customer.whatsapp.replace(/[^0-9]/g, "")}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-emerald-500 hover:underline inline-flex items-center gap-1"
                          >
                            <span>{order.customer.whatsapp}</span>
                            <ExternalLinkIcon size={11} />
                          </a>
                        </p>
                      </div>
                    )}

                    {order.customer.other_phone && (
                      <div>
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                          Secondary Phone
                        </span>
                        <p className="text-muted font-medium mt-0.5">
                          {order.customer.other_phone}
                        </p>
                      </div>
                    )}

                    <div>
                      <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                        Delivery Address
                      </span>
                      <p className="text-muted leading-relaxed mt-0.5">
                        {order.customer.address ?? "No shipping address on file"}
                      </p>
                    </div>
                  </div>
                </div>

                <div className="mt-4 border-t border-line/60 pt-3">
                  <Link
                    href={`/customers/view/?id=${order.customer.id}`}
                    className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-line bg-subtle/50 px-3 py-2 text-xs font-semibold text-ink shadow-2xs hover:bg-subtle hover:text-brand transition"
                  >
                    <span>View Client Order History</span>
                    <ChevronRightIcon size={13} />
                  </Link>
                </div>
              </div>

              {/* Job Specifications Grid (2 cols) */}
              <div className="lg:col-span-2 rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col justify-between">
                <div>
                  <div className="flex items-center justify-between gap-3 border-b border-line pb-3">
                    <div>
                      <h3 className="text-sm font-bold text-ink">Job Specifications & Pricing</h3>
                      <p className="text-xs text-muted">
                        Order parameters, delivery targets, and commercial pricing breakdown.
                      </p>
                    </div>

                    {order.abilities?.update && (
                      <Button
                        size="sm"
                        variant="secondary"
                        icon={<PencilIcon size={12} />}
                        onClick={() => setEditing(true)}
                        className="text-xs font-semibold"
                      >
                        Edit Specs
                      </Button>
                    )}
                  </div>

                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 mt-4">
                    <Detail label="Item Category" icon={ShirtIcon}>
                      {order.product_category?.name ?? "—"}
                    </Detail>
                    <Detail label="Order Type" icon={LayersIcon}>
                      {order.order_type?.name ?? "—"}
                    </Detail>
                    <Detail label="Gross Quantity" icon={BoxesIcon}>
                      {order.gross_quantity} pieces
                    </Detail>
                    <Detail label="Target Due Date" icon={CalendarIcon}>
                      <div className="flex items-center gap-1.5">
                        <span>{formatBusinessDate(order.due_date)}</span>
                        {dueCountdown.isOverdue && (
                          <Badge tone="danger">Overdue</Badge>
                        )}
                      </div>
                    </Detail>
                    <Detail label="Payment Status" icon={CreditCardIcon}>
                      {PAYMENT_STATUS_LABELS[order.payment_status]}
                    </Detail>
                    <Detail label="Approval Rule" icon={SparklesIcon}>
                      {order.urgent.is_urgent ? "Urgent Dual Sign-off" : "Standard Intake"}
                    </Detail>
                  </div>

                  {/* Commercial Invoice Pricing Breakdown */}
                  <div className="mt-5 rounded-xl border border-line bg-subtle/20 p-4">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-muted block mb-2.5">
                      Financial Breakdown
                    </span>
                    <div className="grid gap-2 sm:grid-cols-4 text-xs">
                      <div>
                        <span className="text-muted">Unit Price</span>
                        <p className="font-semibold text-ink mt-0.5">
                          {formatMoney(order.pricing.unit_price_minor ?? 0, order.currency)}
                        </p>
                      </div>
                      <div>
                        <span className="text-muted">Delivery Fee</span>
                        <p className="font-semibold text-ink mt-0.5">
                          {formatMoney(order.pricing.delivery_fee_minor, order.currency)}
                        </p>
                      </div>
                      <div>
                        <span className="text-muted">Discount</span>
                        <p className="font-semibold text-ink mt-0.5">
                          {formatMoney(order.pricing.discount_minor, order.currency)}
                        </p>
                      </div>
                      <div>
                        <span className="text-muted font-bold">Total Invoice</span>
                        <p className="font-extrabold text-sm text-ink mt-0.5">
                          {formatMoney(order.pricing.total_minor, order.currency)}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Bottom Quick Links to Pipeline */}
                <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-line/60 pt-3 text-xs">
                  <span className="text-muted font-medium mr-1">Quick Access:</span>
                  <Link
                    href={`/orders/garment-list/?id=${order.id}`}
                    className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1 text-xs font-semibold text-ink hover:border-brand hover:text-brand transition"
                  >
                    <ShirtIcon size={12} />
                    <span>Garment Roster & Sizing</span>
                  </Link>

                  {order.design_task && (
                    <Link
                      href={`/designs/view/?id=${order.design_task.id}`}
                      className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1 text-xs font-semibold text-ink hover:border-brand hover:text-brand transition"
                    >
                      <SparklesIcon size={12} />
                      <span>Artwork Workspace</span>
                    </Link>
                  )}

                  {order.bulk?.job && (
                    <Link
                      href={`/bulk-jobs/view/?id=${order.bulk.job.id}`}
                      className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1 text-xs font-semibold text-ink hover:border-brand hover:text-brand transition"
                    >
                      <BoxesIcon size={12} />
                      <span>Bulk Job Card</span>
                    </Link>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Tab 2: Financials & Payments */}
        {activeTab === "payments" && (
          <PaymentsPanel order={order} onRecordClick={() => setRecording(true)} />
        )}

        {/* Tab 3: Design & Manufacturing */}
        {activeTab === "production" && (
          <div className="flex flex-col gap-6">
            <JobDetailsPanel key={`details-${order.version}`} order={order} />
            <ProductionPanel order={order} />

            {order.job_details === undefined && (
              <div className="rounded-2xl border border-line bg-surface p-10 text-center shadow-xs">
                <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-subtle text-muted mb-3">
                  <ShirtIcon size={24} />
                </div>
                <h3 className="text-sm font-bold text-ink">
                  Job Specifications & Artwork Pipeline Locked
                </h3>
                <p className="text-xs text-muted max-w-md mx-auto mt-1 leading-relaxed">
                  Detailed fabric selection, design instructions, vector attachments, and factory cutting release unlock once the initial 30% advance deposit is verified.
                </p>
                {!a.meets_requirement && (
                  <div className="mt-4">
                    <Button size="sm" onClick={() => setRecording(true)}>
                      Record Advance Payment
                    </Button>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* Tab 4: Audit Timeline */}
        {activeTab === "history" && (
          <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col gap-4">
            <div>
              <h3 className="text-sm font-bold text-ink">Job Status & Audit History</h3>
              <p className="text-xs text-muted">
                Complete traceability log of every state change, staff action, and administrative decision.
              </p>
            </div>

            {order.history && order.history.length > 0 ? (
              <ol className="relative ml-3 border-l-2 border-line/70 space-y-6 pt-2 text-xs">
                {order.history.map((h, i) => (
                  <li key={i} className="ml-5">
                    <span className="absolute -left-2 mt-1.5 size-3.5 rounded-full border-2 border-surface bg-brand shadow-xs" />
                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                      <span className="font-bold text-sm text-ink">
                        {h.from ? `${h.from} → ${h.to}` : h.to}
                      </span>
                      <span className="text-muted">· {formatDateTime(h.at)}</span>
                      {h.actor && (
                        <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink border border-line/60">
                          by {h.actor}
                        </span>
                      )}
                    </div>
                    {h.reason && (
                      <p className="mt-1 text-xs text-muted bg-subtle/40 p-2.5 rounded-xl border border-line/50 italic max-w-xl">
                        &ldquo;{h.reason}&rdquo;
                      </p>
                    )}
                  </li>
                ))}
              </ol>
            ) : (
              <EmptyState
                title="No history recorded"
                description="Status transitions will be audited here once the order advances through the workflow."
              />
            )}
          </div>
        )}
      </div>

      {/* Edit Specifications Modal */}
      <Modal open={editing} title="Edit Job Specification" onClose={() => setEditing(false)}>
        {editing && <EditSpecForm order={order} onDone={() => setEditing(false)} />}
      </Modal>

      {/* Record Payment Modal */}
      <Modal
        open={recording}
        title={`Record Payment — Job ${order.order_no}`}
        onClose={() => setRecording(false)}
      >
        {recording && (
          <RecordPaymentForm
            orderId={order.id}
            currency={order.currency}
            suggestedMinor={a.shortfall_minor}
            onDone={() => setRecording(false)}
          />
        )}
      </Modal>
    </div>
  );
}

export function OrderScreen() {
  return (
    <Suspense fallback={<LoadingState label="Loading order view…" />}>
      <WithIdParam>
        {(id) => (id === null ? <EmptyState title="Order not found" /> : <OrderDetail id={id} />)}
      </WithIdParam>
    </Suspense>
  );
}
