import type { Metadata } from "next";
import { DeliveryScreen } from "@/features/delivery/delivery-screen";

export const metadata: Metadata = { title: "Delivery" };

export default function Page() {
  return <DeliveryScreen />;
}
