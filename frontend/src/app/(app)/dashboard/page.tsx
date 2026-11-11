import type { Metadata } from "next";
import { RoleDashboard } from "@/features/dashboard/role-dashboard";
import { WelcomePanel } from "@/features/dashboard/welcome-panel";

export const metadata: Metadata = { title: "Dashboard · Garment ERP" };

export default function DashboardPage() {
  return (
    <div className="flex flex-col gap-8">
      <WelcomePanel />
      <RoleDashboard />
    </div>
  );
}
