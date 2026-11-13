"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { AlertCircleIcon, ShieldIcon, UserIcon } from "@/components/ui/icons";
import { applyServerErrors } from "@/lib/forms";
import { safeInAppPath } from "@/lib/safe-path";
import { authKeys, login } from "./api";

// Client-side validation is UX only; the API validates again.
const schema = z.object({
  email: z.string().trim().min(1, "Email is required").max(191).email("Enter a valid email"),
  password: z.string().min(1, "Password is required").max(200),
});

type LoginValues = z.infer<typeof schema>;

/** Only same-origin relative paths are allowed as post-login targets (open-redirect protection). */
export function safeNextPath(next: string | null): string {
  const path = safeInAppPath(next);
  return path === null || path.startsWith("/login") ? "/dashboard/" : path;
}

export function LoginForm() {
  const queryClient = useQueryClient();
  const params = useSearchParams();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<LoginValues>({ resolver: zodResolver(schema), defaultValues: { email: "", password: "" } });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    try {
      const user = await login(values);
      queryClient.setQueryData(authKeys.me, user);
      window.location.assign(safeNextPath(params.get("next")));
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError, ["email", "password"]));
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
      <TextField
        label="Email"
        type="email"
        placeholder="staff@garmenterp.com"
        autoComplete="username"
        icon={<UserIcon size={16} />}
        maxLength={191}
        error={errors.email?.message}
        {...form.register("email")}
      />
      <TextField
        label="Password"
        type="password"
        placeholder="••••••••••••"
        autoComplete="current-password"
        icon={<ShieldIcon size={16} />}
        maxLength={200}
        error={errors.password?.message}
        {...form.register("password")}
      />
      <div className="pt-2">
        <Button type="submit" size="lg" className="w-full" loading={isSubmitting}>
          Sign in
        </Button>
      </div>
    </form>
  );
}
