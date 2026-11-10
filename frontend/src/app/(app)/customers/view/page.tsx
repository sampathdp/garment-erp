import type { Metadata } from "next";
import { CustomerScreen } from "@/features/customers/screens";

export const metadata: Metadata = { title: "Customer" };

export default function Page() {
  return <CustomerScreen />;
}
