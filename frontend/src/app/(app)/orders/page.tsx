import type { Metadata } from "next";
import { OrdersScreen } from "@/features/orders/orders-screen";

export const metadata: Metadata = { title: "Orders" };

export default function Page() {
  return <OrdersScreen />;
}
