"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, PageHeader, SelectField } from "@/components/ui/form-controls";
import { ChevronRightIcon, SearchIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { formatBusinessDate } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { type Delivery, deliveryStatusTone, useDeliveries } from "./api";

/** Jobs whose production is completed (a delivery exists only from sewing completion). */
export function DeliveryDashboard() {
  const search = useSearchParams();
  const [params, setParams] = useState({ page: 1, search: "", status: "", open: true, missing: search.get("missing") === "1", sort: "due_date" });
  const query = useDeliveries(useDebounced(params));

  const columns: Column<Delivery>[] = [
    {
      key: "job",
      header: "Job No.",
      cell: (d) => (
        <Link href={`/deliveries/view/?id=${d.id}`} className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition">
          {d.job_no}
        </Link>
      ),
    },
    {
      key: "customer",
      header: "Customer / due",
      sortKey: "due_date",
      cell: (d) => (
        <div className="flex flex-col">
          <span className="font-medium text-ink">{d.customer.name}</span>
          <span className="flex items-center gap-1.5 text-[11px] text-muted">{formatBusinessDate(d.order.due_date)} {d.order.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}</span>
        </div>
      ),
    },
    { key: "page", header: "Company / Shop", cell: (d) => <span className="text-xs">{d.page.code}</span> },
    { key: "qty", header: "Qty", cell: (d) => <span className="font-semibold text-ink">{d.quantity}</span> },
    { key: "product", header: "Product", cell: (d) => <span className="text-xs">{d.product.category ?? "—"}{d.product.material ? ` · ${d.product.material}` : ""}</span> },
    { key: "production", header: "Production status", cell: (d) => <span className="text-xs">{d.production_status.label}</span> },
    {
      key: "status",
      header: "Delivery",
      cell: (d) => (
        <div className="flex flex-col items-start gap-1">
          <Badge tone={deliveryStatusTone(d.status.value, d.production_status.is_completed)} dot>{d.production_status.is_completed ? "Completed" : d.status.label}</Badge>
          {d.method && <span className="text-[11px] text-muted">{d.method.label}</span>}
          {d.status.value !== "ready" && d.documents_missing.length > 0 && <Badge tone="warning">Documents missing</Badge>}
        </div>
      ),
    },
    { key: "balance", header: "Balance", cell: (d) => <span className={`text-xs ${d.balance.amount_minor > 0 ? "font-semibold text-ink" : "text-muted"}`}>{d.balance.formatted}</span> },
    {
      key: "actions",
      header: "Actions",
      className: "text-right",
      cell: (d) => (
        <div className="flex items-center justify-end gap-1.5">
          <Link
            href={`/deliveries/view/?id=${d.id}`}
            className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-brand/40 hover:bg-subtle hover:text-brand transition shrink-0"
          >
            <span>View</span>
            <ChevronRightIcon size={13} />
          </Link>
        </div>
      ),
    },
  ];

  return (
    <RequirePermission anyOf={["delivery.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Deliveries" description="Produced jobs ready for delivery, out for delivery and delivered. Upload the delivery documents and complete the job once the balance is verified." />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs lg:flex-row lg:items-end">
          <div className="w-full lg:max-w-sm">
            <TextField label="Search" placeholder="Job no. or customer…" icon={<SearchIcon size={16} />} maxLength={100} value={params.search} onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))} />
          </div>
          <SelectField
            label="Delivery status"
            options={[{ value: "", label: "All" }, { value: "ready", label: "Ready for delivery" }, { value: "dispatched", label: "Out for delivery" }, { value: "delivered", label: "Delivered" }]}
            value={params.status}
            onChange={(e) => setParams((p) => ({ ...p, page: 1, status: e.target.value }))}
          />
          <div className="flex gap-4 pb-2">
            <Checkbox label="Hide completed jobs" checked={params.open} onChange={(e) => setParams((p) => ({ ...p, page: 1, open: e.target.checked }))} />
            <Checkbox label="Missing documents" checked={params.missing} onChange={(e) => setParams((p) => ({ ...p, page: 1, missing: e.target.checked }))} />
          </div>
        </div>
        <DataTable
          caption="Deliveries"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(d) => d.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No deliveries"
        />
      </div>
    </RequirePermission>
  );
}
