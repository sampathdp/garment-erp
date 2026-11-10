import type { Metadata } from "next";
import { BranchesScreen } from "@/features/organizations/branches-screen";

export const metadata: Metadata = { title: "Branches" };

export default function BranchesPage() {
  return <BranchesScreen />;
}
