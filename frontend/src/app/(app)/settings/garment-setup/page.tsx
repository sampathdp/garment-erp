import type { Metadata } from "next";
import { GarmentSetupScreen } from "@/features/setup/screens";

export const metadata: Metadata = { title: "Name / number list setup" };

export default function Page() {
  return <GarmentSetupScreen />;
}
