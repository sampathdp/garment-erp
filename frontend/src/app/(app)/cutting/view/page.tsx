import type { Metadata } from "next";
import { CuttingJobScreen } from "@/features/cutting/job-screen";

export const metadata: Metadata = { title: "Cutting job" };

export default function Page() {
  return <CuttingJobScreen />;
}
