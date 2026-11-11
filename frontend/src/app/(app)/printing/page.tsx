import type { Metadata } from "next";
import { PrintingDashboard } from "@/features/printing/dashboard";

export const metadata: Metadata = { title: "Printing" };

export default function Page() {
  return <PrintingDashboard />;
}
