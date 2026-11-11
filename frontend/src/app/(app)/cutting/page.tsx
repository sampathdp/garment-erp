import type { Metadata } from "next";
import { CuttingDashboard } from "@/features/cutting/dashboard";

export const metadata: Metadata = { title: "Cutting" };

export default function Page() {
  return <CuttingDashboard />;
}
