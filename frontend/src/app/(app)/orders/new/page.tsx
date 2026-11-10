import type { Metadata } from "next";
import { OrderForm } from "@/features/orders/order-form";

export const metadata: Metadata = { title: "New order" };

export default function Page() {
  return <OrderForm />;
}
