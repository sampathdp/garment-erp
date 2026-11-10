import type { Metadata } from "next";
import { PaymentScreen } from "@/features/payments/screens";

export const metadata: Metadata = { title: "Payment" };

export default function Page() {
  return <PaymentScreen />;
}
