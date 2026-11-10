import type { Metadata } from "next";
import { CustomersScreen } from "@/features/customers/screens";

export const metadata: Metadata = { title: "Customers" };

export default function Page() {
  return <CustomersScreen />;
}
