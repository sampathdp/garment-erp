"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { formatBusinessDate, formatMoney } from "@/lib/format";
import { type Order, useOrders } from "./api";
import { OrderStatusBadge } from "./orders-screen";

/** Orders of one customer (customer detail screen). */
export function OrdersTableFor({ customerId }: { customerId: number }) {
  const [page, setPage] = useState(1);
  const query = useOrders({ customer_id: customerId, page, sort: "-created_at" });
  const columns: Column<Order>[] = [
    {
      key: "no",
      header: "Job No.",
      cell: (o) => (
        <Link className="font-mono text-xs text-brand hover:underline" href={`/orders/view/?id=${o.id}`}>
          {o.order_no}
        </Link>
      ),
    },
    { key: "category", header: "Category", cell: (o) => o.product_category?.name ?? "—" },
    { key: "qty", header: "Qty", cell: (o) => o.gross_quantity },
    { key: "due", header: "Due date", cell: (o) => formatBusinessDate(o.due_date) },
    { key: "total", header: "Total", cell: (o) => formatMoney(o.pricing.total_minor, o.currency) },
    { key: "status", header: "Status", cell: (o) => <OrderStatusBadge order={o} /> },
  ];
  return <DataTable caption="Customer orders" columns={columns} result={query.data} isLoading={query.isPending} error={query.error} rowKey={(o) => o.id} onPageChange={setPage} emptyTitle="No orders yet" />;
}
