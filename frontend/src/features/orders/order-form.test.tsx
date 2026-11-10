import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ToastProvider } from "@/components/ui/toast";
import { daysUntil, emptyOrderForm, orderFormSchema, toCreateOrderInput } from "./order-form-schema";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), useSearchParams: () => new URLSearchParams() }));
vi.mock("@/features/auth/auth-provider", () => ({ useAuth: () => ({ can: () => true, user: null, status: "authenticated" }) }));

const valid = {
  ...emptyOrderForm,
  branch_id: "1",
  page_id: "2",
  customer_name: "Nimal Perera",
  customer_phone: "077 123 4567",
  customer_whatsapp: "0771234567",
  order_type_id: "1",
  due_date: "2026-11-30",
  product_type_id: "3",
  gross_quantity: "10",
  unit_price: "1,000.50",
};

describe("orderFormSchema", () => {
  it("requires the customer information and job specification fields", () => {
    const result = orderFormSchema.safeParse(emptyOrderForm);
    expect(result.success).toBe(false);
    const fields = result.error?.issues.map((i) => i.path[0]);
    expect(fields).toEqual(expect.arrayContaining(["branch_id", "page_id", "customer_name", "customer_phone", "customer_whatsapp", "order_type_id", "due_date", "product_type_id", "gross_quantity", "unit_price"]));
  });

  it("rejects invalid phone numbers and quantities", () => {
    const result = orderFormSchema.safeParse({ ...valid, customer_phone: "12", gross_quantity: "0", customer_other_phone: "abc" });
    expect(result.error?.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(["customer_phone", "gross_quantity", "customer_other_phone"]));
  });

  it("does not require new-customer fields when an existing customer is selected", () => {
    expect(orderFormSchema.safeParse({ ...valid, customer_id: "9", customer_name: "", customer_phone: "", customer_whatsapp: "" }).success).toBe(true);
  });
});

describe("toCreateOrderInput", () => {
  it("sends minor units and never sends totals, status or job numbers", () => {
    const input = toCreateOrderInput(valid, true);
    expect(input).toMatchObject({ branch_id: 1, page_id: 2, gross_quantity: 10, unit_price_minor: 100050, delivery_fee_minor: null, discount_minor: 0, submit: true });
    expect(input.customer).toEqual({ name: "Nimal Perera", phone: "077 123 4567", whatsapp: "0771234567", other_phone: null, address: null });
    expect(Object.keys(input)).not.toEqual(expect.arrayContaining(["status"]));
    expect(input).not.toHaveProperty("total_minor");
    expect(input).not.toHaveProperty("order_no");
  });

  it("sends only the customer id for an existing customer", () => {
    const input = toCreateOrderInput({ ...valid, customer_id: "9" }, false);
    expect(input.customer_id).toBe(9);
    expect(input.customer).toBeUndefined();
    expect(input.submit).toBe(false);
  });
});

describe("daysUntil", () => {
  it("counts calendar days from today in Colombo", () => {
    const now = new Date("2026-11-09T04:30:00Z"); // 10:00 in Colombo
    expect(daysUntil("2026-11-16", now)).toBe(7);
    expect(daysUntil("2026-11-15", now)).toBe(6);
    expect(daysUntil("bad", now)).toBeNull();
    // 23:30 in Colombo on the 9th is still the 9th.
    expect(daysUntil("2026-11-16", new Date("2026-11-09T18:00:00Z"))).toBe(7);
  });
});

describe("OrderForm", () => {
  it("shows validation errors without calling the create endpoint", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
      const url = String(input);
      const body = url.includes("order-types") || url.includes("product-categories") ? { data: { items: [] } } : { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 }, links: { next: null, prev: null } };
      return new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });
    });
    const { OrderForm } = await import("./order-form");
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <OrderForm />
        </ToastProvider>
      </QueryClientProvider>,
    );

    expect(screen.getByText("Customer information")).toBeInTheDocument();
    expect(screen.getByText("Job specifications")).toBeInTheDocument();
    expect(screen.queryByText(/design instructions/i, { selector: "h2" })).toBeNull();

    await userEvent.click(screen.getByRole("button", { name: "Create order" }));

    expect(await screen.findByText("Customer name is required")).toBeInTheDocument();
    expect(screen.getByText("Select the due date")).toBeInTheDocument();
    expect(screen.getByText("Enter the gross quantity (1 or more)")).toBeInTheDocument();
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === "POST")).toBe(false);
  }, 20_000); // renders the whole form; slow on the local disk
});
