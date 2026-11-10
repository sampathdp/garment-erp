import type { Metadata } from "next";
import { WorkflowSettingsScreen } from "@/features/setup/screens";

export const metadata: Metadata = { title: "Workflow settings" };

export default function Page() {
  return <WorkflowSettingsScreen />;
}
