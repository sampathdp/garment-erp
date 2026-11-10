import type { Metadata } from "next";
import { InvoiceScreen } from "@/features/setup/screens";

export const metadata: Metadata = { title: "Invoice" };

export default function Page() {
  return <InvoiceScreen />;
}
