import type { Metadata } from "next";
import { PageHeader } from "@/components/ui/form-controls";
import { AccountSettings } from "@/features/auth/account-settings";

export const metadata: Metadata = { title: "My account" };

export default function AccountPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="My account" description="Your profile, password and access." />
      <AccountSettings />
    </div>
  );
}
