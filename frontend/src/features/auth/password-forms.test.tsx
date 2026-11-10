import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ForgotPasswordForm } from "./forgot-password-form";
import { ResetPasswordForm } from "./reset-password-form";

let search = new URLSearchParams();
vi.mock("next/navigation", () => ({ useSearchParams: () => search }));

function mockApi(status: number, body: unknown) {
  return vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
    if (String(input).endsWith("/sanctum/csrf-cookie")) {
      document.cookie = "XSRF-TOKEN=t; path=/";
      return new Response(null, { status: 204 });
    }
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  });
}

afterEach(() => {
  vi.restoreAllMocks();
  search = new URLSearchParams();
});

describe("ForgotPasswordForm", () => {
  it("shows the server's generic confirmation after submitting", async () => {
    const fetchMock = mockApi(202, { message: "If an active account exists for this email, a reset link has been sent." });
    render(<ForgotPasswordForm />);

    await userEvent.type(screen.getByLabelText("Email"), "staff@example.com");
    await userEvent.click(screen.getByRole("button", { name: "Send reset link" }));

    expect(await screen.findByRole("status")).toHaveTextContent("If an active account exists");
    const call = fetchMock.mock.calls.find(([url]) => String(url).includes("/auth/forgot-password"));
    expect(call?.[1]?.credentials).toBe("include");
    expect((call?.[1]?.headers as Record<string, string>)["X-XSRF-TOKEN"]).toBe("t");
  });
});

describe("ResetPasswordForm", () => {
  it("refuses incomplete links", () => {
    render(<ResetPasswordForm />);
    expect(screen.getByRole("alert")).toHaveTextContent("incomplete");
  });

  it("validates the password policy before calling the API", async () => {
    search = new URLSearchParams({ token: "abc", email: "staff@example.com" });
    const fetchMock = vi.spyOn(globalThis, "fetch");
    render(<ResetPasswordForm />);

    await userEvent.type(screen.getByLabelText("New password"), "short");
    await userEvent.type(screen.getByLabelText("Confirm new password"), "short");
    await userEvent.click(screen.getByRole("button", { name: "Set password" }));

    expect(await screen.findByText("Use at least 10 characters")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("explains expired links", async () => {
    search = new URLSearchParams({ token: "abc", email: "staff@example.com" });
    mockApi(422, { message: "This password reset link is invalid or has expired.", code: "invalid_reset_token", errors: { token: ["expired"] } });
    render(<ResetPasswordForm />);

    await userEvent.type(screen.getByLabelText("New password"), "NewSecret2026");
    await userEvent.type(screen.getByLabelText("Confirm new password"), "NewSecret2026");
    await userEvent.click(screen.getByRole("button", { name: "Set password" }));

    expect(await screen.findByText(/invalid or has expired/)).toBeInTheDocument();
  });
});
