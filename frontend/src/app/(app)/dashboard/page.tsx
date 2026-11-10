import type { Metadata } from "next";
import { WelcomePanel } from "@/features/dashboard/welcome-panel";

export const metadata: Metadata = { title: "Dashboard · Garment ERP" };

export default function DashboardPage() {
  return <WelcomePanel />;
}
