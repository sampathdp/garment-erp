import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { LoginForm, safeNextPath } from "./login-form";

vi.mock("next/navigation", () => ({ useSearchParams: () => new URLSearchParams() }));

function renderForm() {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <LoginForm />
    </QueryClientProvider>,
  );
}

describe("LoginForm", () => {
  it("validates required fields before calling the API", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch");
    renderForm();

    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByText("Email is required")).toBeInTheDocument();
    expect(screen.getByText("Password is required")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows the server's credential error on the email field", async () => {
    vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
      if (String(input).endsWith("/sanctum/csrf-cookie")) {
        document.cookie = "XSRF-TOKEN=t; path=/";
        return new Response(null, { status: 204 });
      }
      return new Response(JSON.stringify({ message: "The given data was invalid.", code: "validation_failed", errors: { email: ["These credentials do not match our records."] } }), {
        status: 422,
        headers: { "Content-Type": "application/json" },
      });
    });
    renderForm();

    await userEvent.type(screen.getByLabelText("Email"), "staff@example.com");
    await userEvent.type(screen.getByLabelText("Password"), "wrong");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByText("These credentials do not match our records.")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
  });
});

describe("safeNextPath", () => {
  it("only allows same-origin relative paths", () => {
    expect(safeNextPath("/settings/branches/")).toBe("/settings/branches/");
    expect(safeNextPath("https://evil.example")).toBe("/dashboard/");
    expect(safeNextPath("//evil.example")).toBe("/dashboard/");
    expect(safeNextPath("/\\evil.example")).toBe("/dashboard/");
    expect(safeNextPath("/login/")).toBe("/dashboard/");
    // Browsers strip TAB/LF, which would turn these into //evil.example.
    expect(safeNextPath("/\t/evil.example")).toBe("/dashboard/");
    expect(safeNextPath("/\n/evil.example")).toBe("/dashboard/");
    expect(safeNextPath(null)).toBe("/dashboard/");
  });
});
