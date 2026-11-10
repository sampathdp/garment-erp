"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, PageHeader, SelectField } from "@/components/ui/form-controls";
import { PlusIcon, SearchIcon, AlertTriangleIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { useAuth } from "@/features/auth/auth-provider";
import { formatBusinessDate, formatMoney } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { type Order, type OrderGroup, PAYMENT_STATUS_LABELS, statusTone, useOrders } from "./api";

export function OrderStatusBadge({ order }: { order: Pick<Order, "status"> }) {
  return (
    <Badge tone={statusTone(order.status.value)} dot>
      {order.status.label}
    </Badge>
  );
}

export function PaymentStatusCell({ order }: { order: Order }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs font-semibold text-ink">
        {PAYMENT_STATUS_LABELS[order.payment_status]}
      </span>
      {!order.advance.meets_requirement && order.status.value !== "draft" && (
        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-danger">
          <span className="size-1 rounded-full bg-danger" />
          Shortfall {formatMoney(order.advance.shortfall_minor, order.currency)}
        </span>
      )}
    </div>
  );
}

const PRESETS: Record<"all" | OrderGroup, { title: string; description: string; statuses?: { value: string; label: string }[] }> = {
  all: { title: "All Orders", description: "All garment jobs across your authorized branches and sales pages." },
  intake: {
    title: "Drafts and Pending Payment",
    description: "Jobs waiting to be finalized or awaiting verified 30% advance deposit.",
    statuses: [
      { value: "", label: "All intake statuses" },
      { value: "draft", label: "Draft" },
      { value: "pending_payment_verification", label: "Pending Payment Verification" },
      { value: "payment_rejected", label: "Payment Rejected" },
    ],
  },
  urgent: {
    title: "Urgent Approvals",
    description: "Jobs due in fewer than 7 working days. Dual sign-off required from Second Admin and Operations Admin.",
    statuses: [
      { value: "", label: "All urgent statuses" },
      { value: "urgent_approval_pending", label: "Urgent Approval Pending" },
      { value: "urgent_approval_rejected", label: "Urgent Approval Rejected" },
    ],
  },
  approved: { title: "Approved Orders", description: "Jobs cleared after payment verification and ready for design & bulk production." },
};

export function OrdersScreen({ group }: { group?: OrderGroup }) {
  const preset = PRESETS[group ?? "all"];
  const { can } = useAuth();
  const [params, setParams] = useState({ page: 1, sort: group === "approved" ? "due_date" : "-created_at", search: "", status: "" });
  const query = useOrders({ ...useDebounced(params), group });

  const columns: Column<Order>[] = [
    {
      key: "order_no",
      header: "Job No.",
      sortKey: "order_no",
      cell: (o) => (
        <Link
          href={`/orders/view/?id=${o.id}`}
          className="inline-flex items-center rounded-md bg-brand/10 px-2 py-0.5 font-mono text-xs font-bold text-brand hover:bg-brand/20 transition"
        >
          {o.order_no}
        </Link>
      ),
    },
    {
      key: "customer",
      header: "Customer",
      cell: (o) => (
        <div className="flex flex-col">
          <span className="font-medium text-ink">{o.customer.name}</span>
          <span className="text-[11px] text-muted">{o.customer.phone}</span>
        </div>
      ),
    },
    {
      key: "page",
      header: "Channel / Branch",
      cell: (o) => (
        <div className="flex items-center gap-1.5 text-xs">
          <span className="font-semibold text-ink">{o.page.code}</span>
          <span className="text-muted">/</span>
          <span className="text-muted">{o.branch.code}</span>
        </div>
      ),
    },
    { key: "category", header: "Category", cell: (o) => o.product_category?.name ?? "—" },
    {
      key: "qty",
      header: "Qty",
      cell: (o) => <span className="font-semibold text-ink">{o.gross_quantity}</span>,
      className: "text-right",
    },
    {
      key: "due",
      header: "Due date",
      sortKey: "due_date",
      cell: (o) => (
        <div className="flex items-center gap-1.5">
          <span className="text-xs font-medium text-ink">{formatBusinessDate(o.due_date)}</span>
          {o.urgent.is_urgent && (
            <Badge tone="danger" dot>
              Urgent
            </Badge>
          )}
        </div>
      ),
    },
    { key: "status", header: "Status", cell: (o) => <OrderStatusBadge order={o} /> },
    { key: "payment", header: "Payment", cell: (o) => <PaymentStatusCell order={o} /> },
    {
      key: "approval",
      header: "Approval",
      cell: (o) =>
        o.urgent.is_urgent ? (
          <span className={o.urgent.status === "rejected" ? "font-semibold text-danger text-xs" : "text-xs font-medium text-muted"}>
            {o.approval_status}
          </span>
        ) : (
          <span className="text-xs text-muted">Standard</span>
        ),
    },
  ];

  return (
    <RequirePermission anyOf={["orders.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title={preset.title}
          description={preset.description}
          actions={
            can("orders.create") ? (
              <Link
                href="/orders/new/"
                className="inline-flex h-9 items-center gap-1.5 rounded-xl bg-brand px-4 text-xs font-semibold text-white shadow-xs hover:bg-brand-strong transition active:scale-[0.98]"
              >
                <PlusIcon size={16} />
                <span>New order</span>
              </Link>
            ) : undefined
          }
        />

        {/* Filters Toolbar */}
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center">
          <div className="flex-1">
            <TextField
              label="Search orders"
              placeholder="Filter by Job no., customer name, or phone…"
              icon={<SearchIcon size={16} />}
              maxLength={100}
              value={params.search}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
            />
          </div>
          {preset.statuses && (
            <div className="w-full sm:w-64">
              <SelectField
                label="Status filter"
                options={preset.statuses}
                value={params.status}
                onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value }))}
              />
            </div>
          )}
        </div>

        <DataTable
          caption={preset.title}
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(o) => o.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No orders found"
          emptyDescription="You only see orders belonging to your assigned branches and sales channels."
        />
      </div>
    </RequirePermission>
  );
}
