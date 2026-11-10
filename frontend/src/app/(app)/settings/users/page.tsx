import type { Metadata } from "next";
import { UsersScreen } from "@/features/users/screens";

export const metadata: Metadata = { title: "Users" };

export default function UsersPage() {
  return <UsersScreen />;
}
