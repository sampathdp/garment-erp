import type { Metadata } from "next";
import { DesignScreen } from "@/features/designs/screens";

export const metadata: Metadata = { title: "Design job" };

export default function Page() {
  return <DesignScreen />;
}
