import type { Metadata } from "next";
import { OrderScreen } from "@/features/orders/order-detail";

export const metadata: Metadata = { title: "Order" };

export default function Page() {
  return <OrderScreen />;
}
