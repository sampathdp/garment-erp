import type { Metadata } from "next";
import { BulkSetupScreen } from "@/features/bulk/setup-screen";

export const metadata: Metadata = { title: "Bulk setup" };

export default function Page() {
  return <BulkSetupScreen />;
}
