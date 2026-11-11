import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ToastProvider } from "@/components/ui/toast";
import type { UserRow } from "./api";
import { SetPasswordForm } from "./user-detail";

vi.mock("@/features/auth/auth-provider", () => ({ useAuth: () => ({ user: null }) }));

const user: UserRow = {
  id: 7,
  name: "Nimal Perera",
  email: "nimal@example.com",
  phone: null,
  is_active: true,
  last_login_at: null,
  deactivated_at: null,
  created_at: null,
  roles: [],
  branches: [],
  pages: [],
};

function mockApi(status: number, body: unknown) {
  return vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
    if (String(input).endsWith("/sanctum/csrf-cookie")) {
      document.cookie = "XSRF-TOKEN=t; path=/";
      return new Response(null, { status: 204 });
    }
    return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  });
}

function renderForm(onDone = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <SetPasswordForm user={user} onDone={onDone} />
      </ToastProvider>
    </QueryClientProvider>,
  );
  return onDone;
}

afterEach(() => vi.restoreAllMocks());

describe("SetPasswordForm", () => {
  it("validates the password policy before calling the API", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch");
    renderForm();

    await userEvent.type(screen.getByLabelText("New password"), "short");
    await userEvent.type(screen.getByLabelText("Confirm new password"), "short");
    await userEvent.click(screen.getByRole("button", { name: "Set password" }));

    expect(await screen.findByText("Use at least 10 characters")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("sends the new password with the sign-out choice", async () => {
    const fetchMock = mockApi(204, null);
    const onDone = renderForm();

    await userEvent.type(screen.getByLabelText("New password"), "NewSecret2026");
    await userEvent.type(screen.getByLabelText("Confirm new password"), "NewSecret2026");
    await userEvent.click(screen.getByLabelText("Sign the user out on all devices"));
    await userEvent.click(screen.getByRole("button", { name: "Set password" }));

    await vi.waitFor(() => expect(onDone).toHaveBeenCalled());
    const call = fetchMock.mock.calls.find(([url]) => String(url).includes("/users/7/password"));
    expect(call?.[1]?.method).toBe("PUT");
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ password: "NewSecret2026", password_confirmation: "NewSecret2026", sign_out: false });
  });

  it("shows server validation errors on the field", async () => {
    mockApi(422, { message: "The given data was invalid.", code: "validation_failed", errors: { password: ["The given password has appeared in a data leak."] }, request_id: "r" });
    const onDone = renderForm();

    await userEvent.type(screen.getByLabelText("New password"), "Password2026");
    await userEvent.type(screen.getByLabelText("Confirm new password"), "Password2026");
    await userEvent.click(screen.getByRole("button", { name: "Set password" }));

    expect(await screen.findByText("The given password has appeared in a data leak.")).toBeInTheDocument();
    expect(onDone).not.toHaveBeenCalled();
  });
});
