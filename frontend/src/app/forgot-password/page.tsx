import type { Metadata } from "next";
import { AuthCard } from "@/components/ui/auth-card";
import { ForgotPasswordForm } from "@/features/auth/forgot-password-form";

export const metadata: Metadata = { title: "Forgot password" };

export default function ForgotPasswordPage() {
  return (
    <AuthCard title="Reset your password" description="Enter your staff email. If the account is active, we will email you a reset link.">
      <ForgotPasswordForm />
    </AuthCard>
  );
}
