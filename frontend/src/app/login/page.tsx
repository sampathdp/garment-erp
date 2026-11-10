import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { AuthCard } from "@/components/ui/auth-card";
import { LoadingState } from "@/components/ui/states";
import { LoginForm } from "@/features/auth/login-form";

export const metadata: Metadata = { title: "Sign in" };

export default function LoginPage() {
  return (
    <AuthCard title="Sign in" description="Use your staff account.">
      {/* LoginForm reads ?next= (useSearchParams), which needs a Suspense boundary in a static export. */}
      <Suspense fallback={<LoadingState />}>
        <LoginForm />
      </Suspense>
      <Link href="/forgot-password/" className="mt-4 block text-center text-sm text-muted hover:text-ink">
        Forgot your password?
      </Link>
    </AuthCard>
  );
}
