"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import type { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { AlertCircleIcon, CheckCircleIcon, ShieldIcon } from "@/components/ui/icons";
import { ApiError } from "@/lib/api-client";
import { applyServerErrors } from "@/lib/forms";
import { resetPassword } from "./api";
import { newPasswordWithConfirmation } from "./password-schema";

type Values = z.infer<typeof newPasswordWithConfirmation>;

/**
 * Completes a reset (or first-time password setup) from the emailed link (?token=&email=).
 * The token is removed from the address bar right away so it does not linger in history.
 */
export function ResetPasswordForm() {
  const params = useSearchParams();
  const [link] = useState(() => ({ token: params.get("token") ?? "", email: params.get("email") ?? "" }));
  const [done, setDone] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({ resolver: zodResolver(newPasswordWithConfirmation), defaultValues: { password: "", password_confirmation: "" } });

  useEffect(() => {
    if (window.location.search) window.history.replaceState(null, "", window.location.pathname);
  }, []);

  if (!link.token || !link.email) {
    return (
      <div className="flex flex-col gap-4">
        <div role="alert" className="flex items-center gap-2 rounded-xl border border-danger/40 bg-danger-soft p-3.5 text-xs text-danger">
          <AlertCircleIcon size={16} className="shrink-0" />
          <span>This reset link is incomplete. Request a new one.</span>
        </div>
        <div className="text-center pt-2">
          <Link href="/forgot-password/" className="text-xs font-semibold text-brand hover:underline">
            Request a new link
          </Link>
        </div>
      </div>
    );
  }

  if (done) {
    return (
      <div className="flex flex-col gap-4">
        <div role="status" className="flex items-start gap-2.5 rounded-xl border border-success/40 bg-success-soft p-3.5 text-xs text-ink">
          <CheckCircleIcon size={16} className="text-success-strong shrink-0 mt-0.5" />
          <span>{done}</span>
        </div>
        <div className="text-center pt-2">
          <Link href="/login/" className="text-xs font-semibold text-brand hover:underline">
            Sign in with your new password
          </Link>
        </div>
      </div>
    );
  }

  const onSubmit = form.handleSubmit(async ({ password, password_confirmation }) => {
    setFormError(null);
    try {
      setDone(await resetPassword({ token: link.token, email: link.email, password, password_confirmation }));
    } catch (error) {
      if (error instanceof ApiError && error.code === "invalid_reset_token") {
        setFormError(error.message);
        return;
      }
      setFormError(applyServerErrors(error, form.setError, ["password", "password_confirmation"]));
    }
  });

  const { errors, isSubmitting } = form.formState;

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      {formError && (
        <div role="alert" className="flex items-center gap-2 rounded-xl border border-danger/40 bg-danger-soft px-3.5 py-2.5 text-xs font-semibold text-danger">
          <AlertCircleIcon size={16} className="shrink-0" />
          <span>{formError}</span>
        </div>
      )}
      <p className="text-xs text-muted">
        Setting password for <strong className="font-semibold text-ink">{link.email}</strong>.
      </p>
      <TextField
        label="New password"
        type="password"
        autoComplete="new-password"
        maxLength={200}
        icon={<ShieldIcon size={16} />}
        hint="At least 10 characters with letters and numbers."
        error={errors.password?.message}
        {...form.register("password")}
      />
      <TextField
        label="Confirm new password"
        type="password"
        autoComplete="new-password"
        maxLength={200}
        icon={<ShieldIcon size={16} />}
        error={errors.password_confirmation?.message}
        {...form.register("password_confirmation")}
      />
      <div className="pt-2">
        <Button type="submit" size="lg" className="w-full" loading={isSubmitting}>
          Set password
        </Button>
      </div>
    </form>
  );
}
