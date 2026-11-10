import type { Metadata } from "next";
import { PageScreen } from "@/features/organizations/pages-screens";

export const metadata: Metadata = { title: "Page" };

export default function PageDetailPage() {
  return <PageScreen />;
}
