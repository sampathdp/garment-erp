import type { Metadata } from "next";
import { RoleScreen } from "@/features/roles/screens";

export const metadata: Metadata = { title: "Role" };

export default function RolePage() {
  return <RoleScreen />;
}
