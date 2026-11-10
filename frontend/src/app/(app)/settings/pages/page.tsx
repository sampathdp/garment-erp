import type { Metadata } from "next";
import { PagesScreen } from "@/features/organizations/pages-screens";

export const metadata: Metadata = { title: "Pages" };

export default function PagesPage() {
  return <PagesScreen />;
}
