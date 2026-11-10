import type { Metadata } from "next";
import { PaymentsScreen } from "@/features/payments/screens";

export const metadata: Metadata = { title: "Payment verification" };

export default function Page() {
  return <PaymentsScreen />;
}
