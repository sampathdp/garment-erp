import type { Metadata } from "next";
import { OrdersScreen } from "@/features/orders/orders-screen";

export const metadata: Metadata = { title: "Drafts and pending payment" };

export default function Page() {
  return <OrdersScreen group="intake" />;
}
