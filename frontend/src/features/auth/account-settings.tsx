"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, FormError, Section } from "@/components/ui/form-controls";
import { BuildingIcon, GlobeIcon, ShieldIcon, UserIcon } from "@/components/ui/icons";
import { useToast } from "@/components/ui/toast";
import { formatDateTime } from "@/lib/format";
import { applyServerErrors } from "@/lib/forms";
import { authKeys, changePassword, updateProfile } from "./api";
import { useAuth } from "./auth-provider";
import { newPasswordField } from "./password-schema";
import type { CurrentUser } from "./types";

const profileSchema = z.object({
  name: z.string().trim().min(2, "Enter your name").max(120),
  phone: z.string().trim().max(20),
});

const passwordSchema = z
  .object({ current_password: z.string().min(1, "Enter your current password").max(200), password: newPasswordField, password_confirmation: z.string().min(1, "Confirm the new password") })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match" })
  .refine((v) => v.password !== v.current_password, { path: ["password"], message: "Choose a password different from the current one" });

function ProfileForm({ user }: { user: CurrentUser }) {
  const queryClient = useQueryClient();
  const { notify } = useToast();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<z.infer<typeof profileSchema>>({ resolver: zodResolver(profileSchema), defaultValues: { name: user.name, phone: user.phone ?? "" } });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    try {
      const updated = await updateProfile({ name: values.name, phone: values.phone === "" ? null : values.phone });
      queryClient.setQueryData(authKeys.me, updated);
      form.reset({ name: updated.name, phone: updated.phone ?? "" });
      notify("Profile saved", "success");
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError, ["name", "phone"]));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-4 md:max-w-md">
      <FormError message={formError} />
      <TextField label="Full Name" autoComplete="name" maxLength={120} error={form.formState.errors.name?.message} {...form.register("name")} />
      <TextField label="Email Address" value={user.email} readOnly disabled hint="Email address changes must be performed by an administrator." />
      <TextField label="Contact Phone" type="tel" autoComplete="tel" maxLength={20} placeholder="077 123 4567" error={form.formState.errors.phone?.message} {...form.register("phone")} />
      <div className="pt-2">
        <Button type="submit" loading={form.formState.isSubmitting} disabled={!form.formState.isDirty}>
          Save profile
        </Button>
      </div>
    </form>
  );
}

function ChangePasswordForm() {
  const { notify } = useToast();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<z.infer<typeof passwordSchema>>({ resolver: zodResolver(passwordSchema), defaultValues: { current_password: "", password: "", password_confirmation: "" } });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    try {
      await changePassword(values);
      form.reset();
      notify("Password changed. You were signed out on your other devices.", "success");
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError, ["current_password", "password", "password_confirmation"]));
    }
  });

  const { errors, isSubmitting } = form.formState;
  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-4 md:max-w-md">
      <FormError message={formError} />
      <TextField label="Current password" type="password" autoComplete="current-password" maxLength={200} error={errors.current_password?.message} {...form.register("current_password")} />
      <TextField label="New password" type="password" autoComplete="new-password" maxLength={200} hint="At least 10 characters with mixed letters and numbers." error={errors.password?.message} {...form.register("password")} />
      <TextField label="Confirm new password" type="password" autoComplete="new-password" maxLength={200} error={errors.password_confirmation?.message} {...form.register("password_confirmation")} />
      <div className="pt-2">
        <Button type="submit" loading={isSubmitting}>
          Change password
        </Button>
      </div>
    </form>
  );
}

export function AccountSettings() {
  const { user } = useAuth();
  if (!user) return null;

  const initials = user.name
    .split(" ")
    .map((n) => n[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  return (
    <div className="flex flex-col gap-6">
      {/* Account Profile Hero Card */}
      <div className="rounded-2xl border border-line bg-surface p-6 shadow-xs">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-4">
            <div className="flex size-14 items-center justify-center rounded-2xl bg-brand/10 text-lg font-bold text-brand">
              {initials}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-xl font-bold text-ink">{user.name}</h1>
                <Badge tone="success" dot>Active</Badge>
              </div>
              <p className="mt-0.5 text-xs text-muted font-mono">{user.email}</p>
            </div>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {user.roles.map((r) => (
              <Badge key={r.name} tone="brand">
                {r.label}
              </Badge>
            ))}
          </div>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Personal Information" description="Update your staff display name and direct phone number.">
          <ProfileForm user={user} />
        </Section>
        <Section title="Security & Password" description="Updating your password revokes active sessions across other devices.">
          <ChangePasswordForm />
        </Section>
      </div>

      <Section title="Data Access Boundaries & Scope" description="Your company and shop permissions configured by system administrators.">
        <div className="grid gap-4 sm:grid-cols-2 text-xs">
          <div className="flex flex-col gap-2 rounded-xl border border-line bg-subtle/40 p-4 sm:col-span-2">
            <div className="flex items-center gap-2 font-bold text-ink text-sm">
              <GlobeIcon size={16} className="text-brand" />
              <span>Companies & Shops</span>
            </div>
            <p className="text-muted leading-relaxed">
              {user.scope.all_pages ? "Full authorization across all company stores and sales channels." : user.scope.pages.map((p) => (p.is_owner ? `${p.name} (Designated Owner)` : p.name)).join(", ") || "No companies or shops assigned."}
            </p>
          </div>
          <div className="flex justify-between items-center rounded-xl border border-line bg-surface p-4">
            <span className="font-semibold text-muted">Last Session Sign-in:</span>
            <span className="font-medium text-ink">{formatDateTime(user.last_login_at)}</span>
          </div>
          <div className="flex justify-between items-center rounded-xl border border-line bg-surface p-4">
            <span className="font-semibold text-muted">Password Last Changed:</span>
            <span className="font-medium text-ink">{formatDateTime(user.password_changed_at)}</span>
          </div>
        </div>
      </Section>
    </div>
  );
}
