import type { Metadata } from "next";
import { JobCardScreen } from "@/features/bulk/job-screen";

export const metadata: Metadata = { title: "Job card" };

export default function Page() {
  return <JobCardScreen />;
}
