import type { Metadata } from "next";
import { RolesScreen } from "@/features/roles/screens";

export const metadata: Metadata = { title: "Roles" };

export default function RolesPage() {
  return <RolesScreen />;
}
