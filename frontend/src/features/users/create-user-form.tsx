"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { FormError, Section } from "@/components/ui/form-controls";
import { useToast } from "@/components/ui/toast";
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

export function CreateUserForm() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { notify } = useToast();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      email: "",
      phone: "",
      roles: [],
      scope: { branch_ids: [], page_ids: [], owner_page_ids: [] },
      password: "",
      password_confirmation: "",
    },
  });

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
        `${user.name} was created. Share the password with them securely.`,
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
  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
      <FormError message={formError} />
      <Section
        title="Details"
        description="The user signs in with the password you set here. No email is sent; give them the password securely and ask them to change it under My account."
      >
        <div className="grid gap-4 md:grid-cols-3">
          <TextField
            label="Full name"
            autoComplete="off"
            maxLength={120}
            error={errors.name?.message}
            {...form.register("name")}
          />
          <TextField
            label="Email"
            type="email"
            autoComplete="off"
            maxLength={191}
            error={errors.email?.message}
            {...form.register("email")}
          />
          <TextField
            label="Phone"
            type="tel"
            maxLength={20}
            error={errors.phone?.message}
            {...form.register("phone")}
          />
          <TextField
            label="Password"
            type="password"
            autoComplete="new-password"
            maxLength={200}
            hint="At least 10 characters, with letters and numbers."
            error={errors.password?.message}
            {...form.register("password")}
          />
          <TextField
            label="Confirm password"
            type="password"
            autoComplete="new-password"
            maxLength={200}
            error={errors.password_confirmation?.message}
            {...form.register("password_confirmation")}
          />
        </div>
      </Section>
      <Section
        title="Roles"
        description="You can only grant roles whose permissions you hold yourself."
      >
        <Controller
          control={form.control}
          name="roles"
          render={({ field }) => (
            <RolePicker value={field.value} onChange={field.onChange} />
          )}
        />
      </Section>
      <Section
        title="Branches and pages"
        description="Without assignments the user sees no data, unless a role grants all branches or pages."
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
      <div className="flex flex-wrap items-center gap-4">
        <Button type="submit" loading={isSubmitting}>
          Create user
        </Button>
      </div>
    </form>
  );
}
