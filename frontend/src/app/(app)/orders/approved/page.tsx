import type { Metadata } from "next";
import { OrdersScreen } from "@/features/orders/orders-screen";

export const metadata: Metadata = { title: "Approved orders" };

export default function Page() {
  return <OrdersScreen group="approved" />;
}
