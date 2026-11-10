import { z } from "zod";

/**
 * Mirrors the API password policy (SECURITY_STANDARDS.md §1) for early feedback only.
 * The API is authoritative and also rejects passwords found in breach lists.
 */
export const newPasswordField = z
  .string()
  .min(10, "Use at least 10 characters")
  .max(200)
  .regex(/[A-Za-z]/, "Include at least one letter")
  .regex(/[0-9]/, "Include at least one number");

export const newPasswordWithConfirmation = z
  .object({ password: newPasswordField, password_confirmation: z.string().min(1, "Confirm the new password") })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match" });
