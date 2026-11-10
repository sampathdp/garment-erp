import type { Metadata } from "next";
import { UserScreen } from "@/features/users/screens";

export const metadata: Metadata = { title: "User" };

export default function UserPage() {
  return <UserScreen />;
}
