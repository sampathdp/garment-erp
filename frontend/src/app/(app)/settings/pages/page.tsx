import type { Metadata } from "next";
import { PagesScreen } from "@/features/organizations/pages-screens";

export const metadata: Metadata = { title: "Companies & Shops" };

export default function PagesPage() {
  return <PagesScreen />;
}
