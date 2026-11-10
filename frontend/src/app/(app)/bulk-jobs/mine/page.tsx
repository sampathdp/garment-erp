import type { Metadata } from "next";
import { BulkDashboard } from "@/features/bulk/dashboard";

export const metadata: Metadata = { title: "My bulk jobs" };

export default function Page() {
  return <BulkDashboard variant="mine" />;
}
