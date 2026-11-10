"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { AlertCircleIcon, CheckCircleIcon, UserIcon } from "@/components/ui/icons";
import { applyServerErrors } from "@/lib/forms";
import { requestPasswordReset } from "./api";

const schema = z.object({ email: z.string().trim().min(1, "Email is required").max(191).email("Enter a valid email") });
type Values = z.infer<typeof schema>;

export function ForgotPasswordForm() {
  const [sent, setSent] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: "" } });

  const onSubmit = form.handleSubmit(async ({ email }) => {
    setFormError(null);
    try {
      setSent(await requestPasswordReset(email));
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError, ["email"]));
    }
  });

  if (sent) {
    return (
      <div className="flex flex-col gap-4">
        <div role="status" className="flex items-start gap-2.5 rounded-xl border border-success/40 bg-success-soft p-3.5 text-xs text-ink">
          <CheckCircleIcon size={16} className="text-success-strong shrink-0 mt-0.5" />
          <span>{sent} The link expires in 60 minutes.</span>
        </div>
        <div className="pt-2 text-center">
          <Link href="/login/" className="text-xs font-semibold text-brand hover:underline">
            Back to sign in
          </Link>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      {formError && (
        <div role="alert" className="flex items-center gap-2 rounded-xl border border-danger/40 bg-danger-soft px-3.5 py-2.5 text-xs font-semibold text-danger">
          <AlertCircleIcon size={16} className="shrink-0" />
          <span>{formError}</span>
        </div>
      )}
      <TextField
        label="Email"
        type="email"
        placeholder="staff@garmenterp.com"
        autoComplete="username"
        icon={<UserIcon size={16} />}
        maxLength={191}
        error={form.formState.errors.email?.message}
        {...form.register("email")}
      />
      <div className="pt-2">
        <Button type="submit" size="lg" className="w-full" loading={form.formState.isSubmitting}>
          Send reset link
        </Button>
      </div>
    </form>
  );
}
