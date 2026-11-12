"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { FormError, Section } from "@/components/ui/form-controls";
import {
  AlertCircleIcon,
  CheckCircleIcon,
  CheckIcon,
  CopyIcon,
  EyeIcon,
  EyeOffIcon,
  GlobeIcon,
  KeyIcon,
  LockIcon,
  MailIcon,
  PhoneIcon,
  ShieldIcon,
  SparklesIcon,
  StarIcon,
  UserIcon,
} from "@/components/ui/icons";
import { useToast } from "@/components/ui/toast";
import { usePages } from "@/features/organizations/api";
import { useRoles } from "@/features/roles/api";
import { applyServerErrors } from "@/lib/forms";
import { RolePicker, ScopePicker, type ScopeValue } from "./access-pickers";
import { createUser } from "./api";

const schema = z
  .object({
    name: z.string().trim().min(2, "Enter the full name").max(120),
    email: z
      .string()
      .trim()
      .min(1, "Email is required")
      .max(191)
      .email("Enter a valid email"),
    phone: z.string().trim().max(20),
    roles: z.array(z.string()),
    scope: z.object({
      branch_ids: z.array(z.number()),
      page_ids: z.array(z.number()),
      owner_page_ids: z.array(z.number()),
    }),
    password: z
      .string()
      .min(10, "At least 10 characters")
      .max(200)
      .regex(/[A-Za-z]/, "Include a letter")
      .regex(/[0-9]/, "Include a number"),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ["password_confirmation"],
    message: "The passwords do not match",
  });

type Values = z.infer<typeof schema>;

function generateSecurePassword(): string {
  const letters = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz";
  const numbers = "23456789";
  const symbols = "!@#$%^&*";
  const all = letters + numbers + symbols;

  let pwd = "";
  pwd += letters[Math.floor(Math.random() * 24)];
  pwd += letters[24 + Math.floor(Math.random() * 24)];
  pwd += numbers[Math.floor(Math.random() * numbers.length)];
  pwd += numbers[Math.floor(Math.random() * numbers.length)];
  pwd += symbols[Math.floor(Math.random() * symbols.length)];

  while (pwd.length < 14) {
    pwd += all[Math.floor(Math.random() * all.length)];
  }

  return pwd
    .split("")
    .sort(() => 0.5 - Math.random())
    .join("");
}

function getInitials(name: string): string {
  if (!name.trim()) return "??";
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export function CreateUserForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { notify } = useToast();
  const [formError, setFormError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const rolesQuery = useRoles();
  const pagesQuery = usePages({ page: 1, per_page: 100, sort: "name" });

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      email: "",
      phone: "",
      roles: [],
      scope: { branch_ids: [1], page_ids: [], owner_page_ids: [] },
      password: "",
      password_confirmation: "",
    },
    mode: "onChange",
  });

  const [
    watchedName,
    watchedEmail,
    watchedPhone,
    watchedPassword,
    watchedPasswordConfirmation,
    watchedRoles,
    watchedScope,
  ] = useWatch({
    control: form.control,
    name: [
      "name",
      "email",
      "phone",
      "password",
      "password_confirmation",
      "roles",
      "scope",
    ],
  });

  const pwd = watchedPassword ?? "";
  const pwdConf = watchedPasswordConfirmation ?? "";

  const hasMinLength = pwd.length >= 10;
  const hasLetter = /[A-Za-z]/.test(pwd);
  const hasNumber = /[0-9]/.test(pwd);
  const passwordsMatch = pwd.length > 0 && pwd === pwdConf;
  const passwordValid = hasMinLength && hasLetter && hasNumber && passwordsMatch;

  const handleGeneratePassword = async () => {
    const generated = generateSecurePassword();
    form.setValue("password", generated, { shouldValidate: true });
    form.setValue("password_confirmation", generated, { shouldValidate: true });
    setShowPassword(true);
    setShowConfirmPassword(true);

    try {
      await navigator.clipboard.writeText(generated);
      notify("Secure password generated and copied to clipboard!", "success");
    } catch {
      notify("Secure password generated!", "success");
    }
  };

  const onSubmit = form.handleSubmit(async ({ scope, phone, ...values }) => {
    setFormError(null);
    try {
      const user = await createUser({
        ...values,
        phone: phone === "" ? null : phone,
        ...scope,
      });
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      notify(
        `${user.name} was successfully created. Share credentials securely.`,
        "success",
      );
      router.push(`/settings/users/view/?id=${user.id}`);
    } catch (error) {
      setFormError(
        applyServerErrors(error, form.setError, [
          "name",
          "email",
          "phone",
          "password",
        ]),
      );
    }
  });

  const { errors, isSubmitting } = form.formState;

  // Resolved role labels & page names for sidebar preview
  const selectedRoleLabels = (watchedRoles ?? [])
    .map((name) => rolesQuery.data?.find((r) => r.name === name)?.label ?? name);

  const selectedPages = (watchedScope?.page_ids ?? []).map((id) => {
    const found = pagesQuery.data?.data?.find((p) => p.id === id);
    const isOwner = (watchedScope?.owner_page_ids ?? []).includes(id);
    return {
      id,
      name: found?.name ?? `Store #${id}`,
      code: found?.code ?? "",
      isOwner,
    };
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
      <FormError message={formError} />

      <div className="grid gap-6 lg:grid-cols-12 items-start">
        {/* Left Column: Form Sections */}
        <div className="flex flex-col gap-6 lg:col-span-8">
          {/* Section 1: Staff Profile */}
          <Section
            title="Personal Profile & Contact"
            description="Enter the staff member's official name, company email address, and direct phone contact."
          >
            <div className="flex flex-col gap-4">
              <div className="flex items-center gap-4 rounded-xl border border-line/70 bg-subtle/30 p-3.5">
                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand/20 to-brand/5 border border-brand/20 text-brand font-bold text-sm shadow-xs">
                  {getInitials(watchedName ?? "")}
                </div>
                <div className="flex flex-col">
                  <span className="text-xs font-bold text-ink">
                    {watchedName?.trim() || "Staff Member Name"}
                  </span>
                  <span className="text-[11px] text-muted">
                    {watchedEmail?.trim() || "No email assigned yet"}
                  </span>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                  label="Full Name *"
                  placeholder="e.g. Ruwan Silva"
                  autoComplete="off"
                  maxLength={120}
                  icon={<UserIcon size={15} />}
                  error={errors.name?.message}
                  {...form.register("name")}
                />
                <TextField
                  label="Work Email Address *"
                  type="email"
                  placeholder="e.g. ruwan@garmenterp.lk"
                  autoComplete="off"
                  maxLength={191}
                  icon={<MailIcon size={15} />}
                  error={errors.email?.message}
                  {...form.register("email")}
                />
                <div className="sm:col-span-2">
                  <TextField
                    label="Phone Number"
                    type="tel"
                    placeholder="e.g. 077 123 4567"
                    maxLength={20}
                    icon={<PhoneIcon size={15} />}
                    hint="Optional backup contact number for staff coordination."
                    error={errors.phone?.message}
                    {...form.register("phone")}
                  />
                </div>
              </div>
            </div>
          </Section>

          {/* Section 2: Security & Credentials */}
          <Section
            title="Authentication & Security"
            description="Set initial login credentials. The staff member will use this password to sign into the system."
            actions={
              <Button
                type="button"
                variant="outline"
                size="sm"
                icon={<SparklesIcon size={13} className="text-amber-500" />}
                onClick={handleGeneratePassword}
                className="text-xs font-semibold text-brand hover:bg-brand/10 border-brand/30 shrink-0"
              >
                Generate strong password
              </Button>
            }
          >
            <div className="flex flex-col gap-4">
              <div className="grid gap-4 sm:grid-cols-2">
                {/* Password input with toggle */}
                <div className="flex flex-col gap-1.5">
                  <label className="text-xs font-semibold tracking-wide text-ink">
                    Initial Password *
                  </label>
                  <div className="relative flex items-center">
                    <div className="pointer-events-none absolute left-3 text-muted">
                      <LockIcon size={15} />
                    </div>
                    <input
                      type={showPassword ? "text" : "password"}
                      autoComplete="new-password"
                      maxLength={200}
                      placeholder="Minimum 10 characters"
                      {...form.register("password")}
                      className={`h-10 w-full rounded-lg border bg-surface pl-9 pr-10 text-sm text-ink transition-all shadow-xs outline-hidden placeholder:text-muted/60 focus:border-brand focus:ring-2 focus:ring-brand/20 ${
                        errors.password
                          ? "border-danger focus:border-danger focus:ring-danger/20"
                          : "border-line hover:border-muted/40"
                      }`}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="absolute right-3 text-muted hover:text-ink focus:outline-hidden"
                      aria-label={showPassword ? "Hide password" : "Show password"}
                    >
                      {showPassword ? <EyeOffIcon size={15} /> : <EyeIcon size={15} />}
                    </button>
                  </div>
                  {errors.password && (
                    <p className="text-xs text-danger">{errors.password.message}</p>
                  )}
                </div>

                {/* Confirm Password input with toggle */}
                <div className="flex flex-col gap-1.5">
                  <label className="text-xs font-semibold tracking-wide text-ink">
                    Confirm Initial Password *
                  </label>
                  <div className="relative flex items-center">
                    <div className="pointer-events-none absolute left-3 text-muted">
                      <LockIcon size={15} />
                    </div>
                    <input
                      type={showConfirmPassword ? "text" : "password"}
                      autoComplete="new-password"
                      maxLength={200}
                      placeholder="Re-type password"
                      {...form.register("password_confirmation")}
                      className={`h-10 w-full rounded-lg border bg-surface pl-9 pr-10 text-sm text-ink transition-all shadow-xs outline-hidden placeholder:text-muted/60 focus:border-brand focus:ring-2 focus:ring-brand/20 ${
                        errors.password_confirmation
                          ? "border-danger focus:border-danger focus:ring-danger/20"
                          : "border-line hover:border-muted/40"
                      }`}
                    />
                    <button
                      type="button"
                      onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                      className="absolute right-3 text-muted hover:text-ink focus:outline-hidden"
                      aria-label={showConfirmPassword ? "Hide password" : "Show password"}
                    >
                      {showConfirmPassword ? <EyeOffIcon size={15} /> : <EyeIcon size={15} />}
                    </button>
                  </div>
                  {errors.password_confirmation && (
                    <p className="text-xs text-danger">{errors.password_confirmation.message}</p>
                  )}
                </div>
              </div>

              {/* Real-time Password Compliance Checklist */}
              <div className="rounded-xl border border-line bg-subtle/40 p-3.5">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  Password Requirements
                </span>
                <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4 text-xs">
                  <div
                    className={`flex items-center gap-1.5 font-medium transition-colors ${
                      hasMinLength ? "text-emerald-500" : "text-muted"
                    }`}
                  >
                    <span
                      className={`flex size-4 items-center justify-center rounded-full text-[10px] ${
                        hasMinLength ? "bg-emerald-500/15 text-emerald-500" : "bg-line text-muted"
                      }`}
                    >
                      ✓
                    </span>
                    <span>10+ characters</span>
                  </div>
                  <div
                    className={`flex items-center gap-1.5 font-medium transition-colors ${
                      hasLetter ? "text-emerald-500" : "text-muted"
                    }`}
                  >
                    <span
                      className={`flex size-4 items-center justify-center rounded-full text-[10px] ${
                        hasLetter ? "bg-emerald-500/15 text-emerald-500" : "bg-line text-muted"
                      }`}
                    >
                      ✓
                    </span>
                    <span>Contains letter</span>
                  </div>
                  <div
                    className={`flex items-center gap-1.5 font-medium transition-colors ${
                      hasNumber ? "text-emerald-500" : "text-muted"
                    }`}
                  >
                    <span
                      className={`flex size-4 items-center justify-center rounded-full text-[10px] ${
                        hasNumber ? "bg-emerald-500/15 text-emerald-500" : "bg-line text-muted"
                      }`}
                    >
                      ✓
                    </span>
                    <span>Contains number</span>
                  </div>
                  <div
                    className={`flex items-center gap-1.5 font-medium transition-colors ${
                      passwordsMatch ? "text-emerald-500" : "text-muted"
                    }`}
                  >
                    <span
                      className={`flex size-4 items-center justify-center rounded-full text-[10px] ${
                        passwordsMatch ? "bg-emerald-500/15 text-emerald-500" : "bg-line text-muted"
                      }`}
                    >
                      ✓
                    </span>
                    <span>Passwords match</span>
                  </div>
                </div>
              </div>

              {/* Informative Security Callout */}
              <div className="flex items-start gap-2.5 rounded-lg border border-brand/20 bg-brand/5 p-3 text-xs text-ink/80">
                <KeyIcon size={15} className="text-brand shrink-0 mt-0.5" />
                <p className="leading-relaxed">
                  No automated email is sent to the staff member. Share these credentials via a secure
                  channel and advise the user to change their password upon their first sign-in under{" "}
                  <span className="font-semibold text-ink">My account</span>.
                </p>
              </div>
            </div>
          </Section>

          {/* Section 3: Roles */}
          <Section
            title="Role & Capability Assignments"
            description="Grant operational roles defining what actions this staff member can perform."
          >
            <Controller
              control={form.control}
              name="roles"
              render={({ field }) => (
                <RolePicker value={field.value} onChange={field.onChange} />
              )}
            />
          </Section>

          {/* Section 4: Companies & Pages */}
          <Section
            title="Authorized Companies & Stores"
            description="Assign specific sales channels and online shops this staff member has permission to view."
          >
            <Controller
              control={form.control}
              name="scope"
              render={({ field }) => (
                <ScopePicker
                  value={field.value as ScopeValue}
                  onChange={field.onChange}
                />
              )}
            />
          </Section>
        </div>

        {/* Right Column: Sticky Summary & Provisioning Sidebar */}
        <div className="flex flex-col gap-5 lg:col-span-4 lg:sticky lg:top-6">
          <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs">
            <h3 className="text-xs font-bold uppercase tracking-wider text-muted">
              Provisioning Summary
            </h3>

            {/* Staff Card Live Preview */}
            <div className="mt-4 flex flex-col items-center text-center rounded-xl border border-line/60 bg-subtle/40 p-5">
              <div className="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand to-brand-strong text-white font-extrabold text-xl shadow-md shadow-brand/20">
                {getInitials(watchedName ?? "")}
              </div>
              <h4 className="mt-3 text-sm font-bold text-ink">
                {watchedName?.trim() || "New Staff Member"}
              </h4>
              <p className="text-xs text-muted">
                {watchedEmail?.trim() || "email@company.com"}
              </p>
              {watchedPhone?.trim() && (
                <span className="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-muted">
                  <PhoneIcon size={11} />
                  {watchedPhone.trim()}
                </span>
              )}
            </div>

            {/* Provisioning Checklist */}
            <div className="mt-5 flex flex-col gap-3 border-t border-line/60 pt-4 text-xs">
              <div className="flex items-center justify-between">
                <span className="text-muted">Account Profile</span>
                <span
                  className={`font-semibold ${
                    watchedName && watchedEmail ? "text-emerald-500" : "text-amber-500"
                  }`}
                >
                  {watchedName && watchedEmail ? "Complete" : "Incomplete"}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted">Security Password</span>
                <span
                  className={`font-semibold ${
                    passwordValid ? "text-emerald-500" : "text-amber-500"
                  }`}
                >
                  {passwordValid ? "Valid & Strong" : "Requirements Pending"}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted">Assigned Roles</span>
                <span className="font-semibold text-ink">
                  {selectedRoleLabels.length} selected
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted">Store Scope</span>
                <span className="font-semibold text-ink">
                  {selectedPages.length} authorized
                </span>
              </div>
            </div>

            {/* Selected Roles Preview Chips */}
            {selectedRoleLabels.length > 0 && (
              <div className="mt-4 border-t border-line/60 pt-3">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  Assigned Capabilities
                </span>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {selectedRoleLabels.map((label, idx) => (
                    <span
                      key={idx}
                      className="inline-flex items-center gap-1 rounded-md bg-brand/10 border border-brand/20 px-2 py-0.5 text-[11px] font-semibold text-brand"
                    >
                      <ShieldIcon size={10} />
                      {label}
                    </span>
                  ))}
                </div>
              </div>
            )}

            {/* Selected Stores Preview */}
            {selectedPages.length > 0 && (
              <div className="mt-4 border-t border-line/60 pt-3">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                  Authorized Stores
                </span>
                <div className="mt-2 flex flex-col gap-1.5">
                  {selectedPages.slice(0, 4).map((p) => (
                    <div
                      key={p.id}
                      className="flex items-center justify-between text-[11px] text-ink"
                    >
                      <span className="truncate max-w-[170px]">{p.name}</span>
                      {p.isOwner && (
                        <span className="inline-flex items-center gap-0.5 rounded px-1 text-[10px] font-bold bg-amber-500/15 text-amber-500 border border-amber-500/30">
                          <StarIcon size={9} className="fill-amber-500" />
                          Owner
                        </span>
                      )}
                    </div>
                  ))}
                  {selectedPages.length > 4 && (
                    <span className="text-[10px] text-muted font-medium">
                      + {selectedPages.length - 4} more stores
                    </span>
                  )}
                </div>
              </div>
            )}

            {/* Actions */}
            <div className="mt-6 flex flex-col gap-2.5 border-t border-line/60 pt-5">
              <Button
                type="submit"
                loading={isSubmitting}
                className="w-full h-10 font-bold shadow-md shadow-brand/20 justify-center"
              >
                Create user account
              </Button>
              <Link
                href="/settings/users/"
                className="inline-flex h-9 w-full items-center justify-center rounded-xl border border-line bg-surface text-xs font-semibold text-muted hover:bg-subtle hover:text-ink transition"
              >
                Cancel
              </Link>
            </div>
          </div>

          {/* Security & Audit Policy Card */}
          <div className="rounded-xl border border-line/80 bg-subtle/40 p-4 text-xs text-muted">
            <div className="flex items-center gap-1.5 font-semibold text-ink">
              <CheckCircleIcon size={14} className="text-brand" />
              <span>Security & Audit Trail</span>
            </div>
            <p className="mt-1 leading-relaxed text-[11px]">
              User account creation, role assignments, and store scope authorizations are immutably
              recorded in the system audit log.
            </p>
          </div>
        </div>
      </div>
    </form>
  );
}
