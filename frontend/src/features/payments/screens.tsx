"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { CheckCircleIcon, ChevronRightIcon, CreditCardIcon, ExternalLinkIcon, SearchIcon, XIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime, formatMoney } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { type Payment, PAYMENT_METHODS, PAYMENT_STATUS_LABELS, type PaymentStatusValue, useDecidePayment, usePayment, usePayments } from "./api";

const methodLabel = (m: string) => PAYMENT_METHODS.find((x) => x.value === m)?.label ?? m;

export function PaymentStatusBadge({ status }: { status: PaymentStatusValue }) {
  return (
    <Badge tone={status === "verified" ? "success" : status === "rejected" ? "danger" : "brand"} dot>
      {PAYMENT_STATUS_LABELS[status]}
    </Badge>
  );
}

export function PaymentsScreen() {
  const [params, setParams] = useState<{ page: number; status: PaymentStatusValue | ""; search: string; sort: string }>({
    page: 1,
    status: "pending_verification",
    search: "",
    sort: "created_at",
  });
  const query = usePayments(useDebounced(params));

  const columns: Column<Payment>[] = [
    { key: "recorded", header: "Submitted", sortKey: "created_at", cell: (p) => <span className="text-xs text-muted">{formatDateTime(p.recorded_at)}</span> },
    {
      key: "order",
      header: "Job No.",
      cell: (p) => (
        <Link className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition" href={`/payments/view/?id=${p.id}`}>
          {p.order?.order_no}
        </Link>
      ),
    },
    { key: "customer", header: "Customer", cell: (p) => <span className="font-medium text-ink">{p.order?.customer?.name ?? "—"}</span> },
    { key: "amount", header: "Amount", sortKey: "amount", cell: (p) => <span className="font-bold text-ink">{formatMoney(p.amount_minor, p.currency)}</span> },
    {
      key: "method",
      header: "Method",
      cell: (p) => (
        <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-medium text-muted capitalize">
          {methodLabel(p.method)}
        </span>
      ),
    },
    { key: "reference", header: "Reference", cell: (p) => <span className="font-mono text-xs text-muted">{p.reference_no ?? "—"}</span> },
    { key: "by", header: "Submitted by", cell: (p) => <span className="text-xs text-muted">{p.recorded_by?.name ?? "—"}</span> },
    { key: "status", header: "Verification Status", cell: (p) => <PaymentStatusBadge status={p.status} /> },
    {
      key: "actions",
      header: "Actions",
      className: "text-right",
      cell: (p) => (
        <div className="flex items-center justify-end gap-1.5">
          <Link
            href={`/payments/view/?id=${p.id}`}
            className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-brand/40 hover:bg-subtle hover:text-brand transition shrink-0"
          >
            <span>{p.status === "pending_verification" ? "Review" : "View"}</span>
            <ChevronRightIcon size={13} />
          </Link>
        </div>
      ),
    },
  ];

  return (
    <RequirePermission anyOf={["payments.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Payment verification"
          description="Verify customer bank slips and receipts. As a security safeguard, staff cannot verify payments they recorded themselves."
        />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center">
          <div className="w-full sm:w-64">
            <SelectField
              label="Filter by status"
              options={[{ value: "", label: "All payments" }, ...Object.entries(PAYMENT_STATUS_LABELS).map(([value, label]) => ({ value, label }))]}
              value={params.status}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value as PaymentStatusValue | "" }))}
            />
          </div>
          <div className="flex-1">
            <TextField
              label="Search"
              placeholder="Search by Job no. or bank reference code…"
              icon={<SearchIcon size={16} />}
              maxLength={100}
              value={params.search}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
            />
          </div>
        </div>
        <DataTable
          caption="Payments"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(p) => p.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No payments found"
          emptyDescription="Nothing is waiting for verification across your assigned sales pages."
        />
      </div>
    </RequirePermission>
  );
}

function Evidence({ payment }: { payment: Payment }) {
  const e = payment.evidence;
  if (!e) return <p className="text-xs text-muted italic">No receipt or evidence image attached to this payment record.</p>;
  return (
    <div className="flex flex-col gap-3">
      {e.mime_type.startsWith("image/") && (
        // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL; not optimisable
        <div className="overflow-hidden rounded-xl border border-line bg-subtle/30 p-2 max-w-lg">
          <img src={e.url} alt={`Payment evidence: ${e.name}`} className="max-h-[30rem] w-full rounded-lg object-contain shadow-xs" />
        </div>
      )}
      <div className="flex items-center gap-2">
        <a
          href={e.url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-brand shadow-xs hover:bg-subtle transition"
        >
          <span>Open original file ({Math.ceil(e.size_bytes / 1024)} KB)</span>
          <ExternalLinkIcon size={13} />
        </a>
      </div>
      <p className="text-[11px] text-muted">Signed URL security note: this download link automatically expires after several minutes. Reload the page for a refreshed link.</p>
    </div>
  );
}

function PaymentDetail({ id }: { id: number }) {
  const query = usePayment(id);
  const decide = useDecidePayment(id);
  const { notify } = useToast();
  const [mode, setMode] = useState<"verify" | "reject" | null>(null);
  const [text, setText] = useState("");

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const p = query.data;
  const o = p.order;

  const confirm = () =>
    decide.mutate(
      { verify: mode === "verify", text },
      {
        onSuccess: (updated) => {
          notify(updated.status === "verified" ? "Payment confirmed" : "Payment rejected", "success");
          setMode(null);
          setText("");
        },
      },
    );

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Payment for ${o?.order_no ?? ""}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/payments/" className="hover:text-ink">Payments</Link>
            <span>/</span>
            <span className="font-semibold text-ink">Verification #{p.id}</span>
          </div>
        }
        description={`${formatMoney(p.amount_minor, p.currency)} · ${methodLabel(p.method)} · Submitted on ${formatBusinessDate(p.payment_date)}`}
        actions={
          <div className="flex items-center gap-2.5">
            <PaymentStatusBadge status={p.status} />
            {p.abilities?.verify && (
              <Button onClick={() => setMode("verify")}>Confirm payment</Button>
            )}
            {p.abilities?.reject && (
              <Button variant="danger" onClick={() => setMode("reject")}>
                Reject
              </Button>
            )}
          </div>
        }
      />

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Payment Details">
          <dl className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Amount Paid</dt>
              <dd className="text-base font-bold text-ink">{formatMoney(p.amount_minor, p.currency)}</dd>
            </div>
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Payment Method</dt>
              <dd className="text-sm font-semibold capitalize text-ink">{methodLabel(p.method)}</dd>
            </div>
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Reference / Slip No.</dt>
              <dd className="text-sm font-mono font-medium text-ink">{p.reference_no ?? "—"}</dd>
            </div>
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Payment Date</dt>
              <dd className="text-sm font-semibold text-ink">{formatBusinessDate(p.payment_date)}</dd>
            </div>
            <div className="sm:col-span-2 flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Submitted By</dt>
              <dd className="text-xs font-medium text-ink">{p.recorded_by?.name} · {formatDateTime(p.recorded_at)}</dd>
            </div>
            {p.verified_by && (
              <div className="sm:col-span-2 flex flex-col gap-1 rounded-lg bg-success-soft/50 p-3 border border-success/20">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-success-strong">Verified By</dt>
                <dd className="text-xs font-semibold text-success-strong">{p.verified_by.name} · {formatDateTime(p.verified_at)}</dd>
              </div>
            )}
            {p.verification_notes && (
              <div className="sm:col-span-2 flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Verification Notes</dt>
                <dd className="text-xs text-ink">{p.verification_notes}</dd>
              </div>
            )}
            {p.rejection_reason && (
              <div className="sm:col-span-2 flex flex-col gap-1 rounded-lg bg-danger-soft p-3 border border-danger/30">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-danger">Rejection Reason</dt>
                <dd className="text-xs font-semibold text-danger">{p.rejection_reason}</dd>
              </div>
            )}
          </dl>
        </Section>

        {o && (
          <Section title="Associated Job & Advance Requirement">
            <dl className="grid gap-3 sm:grid-cols-2">
              <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Job Number</dt>
                <dd className="text-sm font-bold">
                  <Link className="text-brand hover:underline" href={`/orders/view/?id=${o.id}`}>
                    {o.order_no}
                  </Link>
                  <span className="block mt-0.5 text-xs font-normal text-muted">{o.status.label}</span>
                </dd>
              </div>
              <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Customer</dt>
                <dd className="text-sm font-semibold text-ink">{o.customer?.name ?? "—"}</dd>
              </div>
              <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Order Total</dt>
                <dd className="text-sm font-bold text-ink">{formatMoney(o.total_minor, p.currency)}</dd>
              </div>
              <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">30% Advance Required</dt>
                <dd className="text-sm font-bold text-ink">{formatMoney(o.advance_required_minor, p.currency)}</dd>
              </div>
              <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Confirmed Payments</dt>
                <dd className="text-sm font-bold text-success-strong">{formatMoney(o.verified_minor, p.currency)}</dd>
              </div>
              <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Advance Shortfall</dt>
                <dd className={o.shortfall_minor > 0 ? "text-sm font-bold text-danger" : "text-sm font-bold text-success-strong"}>
                  {o.shortfall_minor > 0 ? formatMoney(o.shortfall_minor, p.currency) : "Fully Satisfied"}
                </dd>
              </div>
            </dl>
            <p className="mt-4 text-xs text-muted leading-relaxed">
              <strong>Workflow Rule:</strong> Verifying a payment records it into confirmed balances. The overall order moves forward only when total confirmed payments satisfy the 30% advance requirement.
            </p>
          </Section>
        )}
      </div>

      <Section title="Payment Evidence / Deposit Slip">
        <Evidence payment={p} />
      </Section>

      {p.history && (
        <Section title="Verification Audit Trail">
          <ol className="relative ml-3 border-l border-line/70 space-y-4 text-xs">
            {p.history.map((h, i) => (
              <li key={i} className="ml-4">
                <span className="absolute -left-1.5 mt-1.5 size-3 rounded-full border-2 border-surface bg-brand" />
                <div className="flex flex-wrap items-center gap-x-2">
                  <span className="font-semibold text-ink capitalize">{h.action}</span>
                  <span className="text-muted">· {formatDateTime(h.at)}</span>
                  {h.actor && <span className="rounded bg-subtle px-1.5 py-0.5 text-muted">by {h.actor}</span>}
                </div>
                {h.notes && <p className="mt-1 text-muted italic">&ldquo;{h.notes}&rdquo;</p>}
              </li>
            ))}
          </ol>
        </Section>
      )}

      <Modal open={mode !== null} title={mode === "verify" ? "Confirm Payment" : "Reject Payment"} onClose={() => setMode(null)}>
        <div className="flex flex-col gap-4">
          <FormError message={decide.error instanceof ApiError ? (decide.error.fieldErrors.reason?.[0] ?? decide.error.message) : null} />
          <TextAreaField
            label={mode === "verify" ? "Verification notes (optional)" : "Reason for rejection *"}
            placeholder={mode === "verify" ? "e.g. Verified against bank statement" : "e.g. Deposit slip reference does not match bank records"}
            maxLength={500}
            value={text}
            onChange={(e) => setText(e.target.value)}
          />
          <div className="flex justify-end gap-2.5 pt-2">
            <Button variant="secondary" onClick={() => setMode(null)}>
              Cancel
            </Button>
            <Button variant={mode === "verify" ? "primary" : "danger"} disabled={mode === "reject" && text.trim().length < 3} loading={decide.isPending} onClick={confirm}>
              {mode === "verify" ? "Confirm Payment" : "Confirm Rejection"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

export function PaymentScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Payment not found" /> : <PaymentDetail id={id} />)}</WithIdParam>;
}
