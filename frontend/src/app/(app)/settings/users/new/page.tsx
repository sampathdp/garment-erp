import type { Metadata } from "next";
import { NewUserScreen } from "@/features/users/screens";

export const metadata: Metadata = { title: "New user" };

export default function NewUserPage() {
  return <NewUserScreen />;
}
