import { api, ensureCsrfCookie } from "@/lib/api-client";
import type { CurrentUser } from "./types";

export const authKeys = {
  me: ["auth", "me"] as const,
};

export async function fetchCurrentUser(signal?: AbortSignal): Promise<CurrentUser> {
  const res = await api.get<{ data: CurrentUser }>("auth/me", undefined, signal);
  return res.data;
}

export async function login(input: { email: string; password: string; remember?: boolean }): Promise<CurrentUser> {
  // Fresh CSRF cookie before authenticating (session is regenerated server-side on success).
  await ensureCsrfCookie(true);
  const res = await api.post<{ data: CurrentUser }>("auth/login", input);
  return res.data;
}

export async function logout(): Promise<void> {
  await api.post<void>("auth/logout");
}

/** Always resolves with the same message whether or not the account exists (no enumeration). */
export async function requestPasswordReset(email: string): Promise<string> {
  const res = await api.post<{ message: string }>("auth/forgot-password", { email });
  return res.message;
}

export async function resetPassword(input: { email: string; token: string; password: string; password_confirmation: string }): Promise<string> {
  const res = await api.post<{ message: string }>("auth/reset-password", input);
  return res.message;
}

export async function changePassword(input: { current_password: string; password: string; password_confirmation: string }): Promise<void> {
  await api.put<void>("auth/password", input);
}

export async function updateProfile(input: { name: string; phone: string | null }): Promise<CurrentUser> {
  const res = await api.patch<{ data: CurrentUser }>("auth/profile", input);
  return res.data;
}
