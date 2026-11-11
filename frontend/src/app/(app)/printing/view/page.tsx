import type { Metadata } from "next";
import { PrintJobScreen } from "@/features/printing/job-screen";

export const metadata: Metadata = { title: "Printing job" };

export default function Page() {
  return <PrintJobScreen />;
}
