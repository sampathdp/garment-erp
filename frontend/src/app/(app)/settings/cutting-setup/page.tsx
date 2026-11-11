import type { Metadata } from "next";
import { CuttingSetupScreen } from "@/features/cutting/setup-screen";

export const metadata: Metadata = { title: "Cutting setup" };

export default function Page() {
  return <CuttingSetupScreen />;
}
