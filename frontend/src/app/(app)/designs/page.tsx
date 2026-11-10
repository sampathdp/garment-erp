import type { Metadata } from "next";
import { DesignDashboard } from "@/features/designs/screens";

export const metadata: Metadata = { title: "Design plan" };

export default function Page() {
  return <DesignDashboard />;
}
