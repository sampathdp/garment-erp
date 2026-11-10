import type { Metadata } from "next";
import { BulkDashboard } from "@/features/bulk/dashboard";

export const metadata: Metadata = { title: "Bulk management" };

export default function Page() {
  return <BulkDashboard variant="manage" />;
}
