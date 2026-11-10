import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthCard } from "@/components/ui/auth-card";
import { LoadingState } from "@/components/ui/states";
import { ResetPasswordForm } from "@/features/auth/reset-password-form";

export const metadata: Metadata = { title: "Set password", referrer: "no-referrer" };

export default function ResetPasswordPage() {
  return (
    <AuthCard title="Choose a new password" description="The link can be used once and expires after 60 minutes.">
      {/* Reads ?token=&email= with useSearchParams: needs a Suspense boundary in a static export. */}
      <Suspense fallback={<LoadingState />}>
        <ResetPasswordForm />
      </Suspense>
    </AuthCard>
  );
}
