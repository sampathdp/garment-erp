import type { Metadata } from "next";
import { OrdersScreen } from "@/features/orders/orders-screen";

export const metadata: Metadata = { title: "Urgent approvals" };

export default function Page() {
  return <OrdersScreen group="urgent" />;
}
