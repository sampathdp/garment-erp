import type { Metadata } from "next";
import { BulkJobScreen } from "@/features/bulk/job-screen";

export const metadata: Metadata = { title: "Bulk job" };

export default function Page() {
  return <BulkJobScreen />;
}
