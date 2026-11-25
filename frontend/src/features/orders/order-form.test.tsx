import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ToastProvider } from "@/components/ui/toast";
import { daysUntil, defaultPageId, emptyOrderForm, orderFormSchema, toCreateOrderInput } from "./order-form-schema";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), useSearchParams: () => new URLSearchParams() }));
vi.mock("@/features/auth/auth-provider", () => ({ useAuth: () => ({ can: () => true, user: null, status: "authenticated" }) }));

const valid = {
  ...emptyOrderForm,
  branch_id: "1",
  page_id: "2",
  customer_name: "Nimal Perera",
  customer_phone: "077 123 4567",
  customer_whatsapp: "0771234567",
  customer_city: "Colombo",
  customer_district: "Colombo",
  due_date: "2026-11-30",
  product_type_id: "3",
  product_type_is_other: false,
  product_type_other: "",
  gross_quantity: "10",
  advance_amount: "3,000.50",
  payment_method: "cash",
};

describe("orderFormSchema", () => {
  it("requires the customer information and job specification fields", () => {
    const result = orderFormSchema.safeParse(emptyOrderForm);
    expect(result.success).toBe(false);
    const fields = result.error?.issues.map((i) => i.path[0]);
    expect(fields).toEqual(expect.arrayContaining(["branch_id", "page_id", "customer_name", "customer_phone", "customer_whatsapp", "customer_city", "customer_district", "due_date", "product_type_id", "gross_quantity", "advance_amount"]));
  });

  it("rejects invalid phone numbers and quantities", () => {
    const result = orderFormSchema.safeParse({ ...valid, customer_phone: "12", gross_quantity: "0", customer_other_phone: "abc" });
    expect(result.error?.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(["customer_phone", "gross_quantity", "customer_other_phone"]));
  });

  it("does not require new-customer fields when an existing customer is selected", () => {
    expect(orderFormSchema.safeParse({ ...valid, customer_id: "9", customer_name: "", customer_phone: "", customer_whatsapp: "", customer_city: "", customer_district: "" }).success).toBe(true);
  });

  it("requires the garment description only when Other is selected", () => {
    expect(orderFormSchema.safeParse({ ...valid, product_type_is_other: true }).success).toBe(false);
    expect(orderFormSchema.safeParse({ ...valid, product_type_is_other: true, product_type_other: "Hoodie" }).success).toBe(true);
    expect(orderFormSchema.safeParse({ ...valid, product_type_is_other: true, product_type_other: "x".repeat(101) }).success).toBe(false);
    expect(orderFormSchema.safeParse({ ...valid, product_type_other: "Ignored" }).success).toBe(true);
  });
});

describe("toCreateOrderInput", () => {
  it("sends minor units and never sends totals, status or job numbers", () => {
    const input = toCreateOrderInput(valid, true);
    expect(input).toMatchObject({ branch_id: 1, page_id: 2, gross_quantity: 10, advance_amount_minor: 300050, delivery_fee_minor: null, discount_minor: 0, submit: true });
    expect(input.customer).toEqual({
      name: "Nimal Perera",
      phone: "077 123 4567",
      whatsapp: "0771234567",
      other_phone: null,
      address_line1: null,
      address_line2: null,
      street: null,
      city: "Colombo",
      district: "Colombo",
      address: "Colombo, Colombo",
    });
    expect(Object.keys(input)).not.toEqual(expect.arrayContaining(["status"]));
    expect(input).not.toHaveProperty("total_minor");
    expect(input).not.toHaveProperty("order_no");
    expect(input).not.toHaveProperty("unit_price_minor"); // priced after printing
  });

  it("sends only the customer id for an existing customer", () => {
    const input = toCreateOrderInput({ ...valid, customer_id: "9" }, false);
    expect(input.customer_id).toBe(9);
    expect(input.customer).toBeUndefined();
    expect(input.submit).toBe(false);
  });

  it("sends the Other category description and clears it for other categories", () => {
    expect(toCreateOrderInput({ ...valid, product_type_is_other: true, product_type_other: "Hoodie" }, false).product_type_other).toBe("Hoodie");
    expect(toCreateOrderInput({ ...valid, product_type_other: "Ignored" }, false).product_type_other).toBeNull();
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

describe("defaultPageId", () => {
  const pages = [
    { id: 1, is_active: true },
    { id: 2, is_active: true },
    { id: 3, is_active: false },
  ];
  it("prefers the page the user owns, then their first assigned page", () => {
    expect(defaultPageId([{ id: 1, is_owner: false }, { id: 2, is_owner: true }], pages)).toBe(2);
    expect(defaultPageId([{ id: 1, is_owner: false }, { id: 2, is_owner: false }], pages)).toBe(1);
  });
  it("skips inactive pages and leaves the choice open when there is no single answer", () => {
    expect(defaultPageId([{ id: 3, is_owner: true }], pages)).toBeNull();
    expect(defaultPageId([], pages)).toBeNull();
    expect(defaultPageId([], [{ id: 5, is_active: true }])).toBe(5);
  });
});

describe("OrderForm", () => {
  it("shows the garment description field only for the Other category", async () => {
    vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
      const url = typeof input === "string" ? input : input instanceof URL ? input.toString() : input.url;
      const body = url.includes("product-categories")
        ? { data: { items: [{ id: 3, code: "other", name: "Other", is_active: true }, { id: 4, code: "jersey", name: "Jersey", is_active: true }] } }
        : { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 }, links: { next: null, prev: null } };
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

    const category = await screen.findByLabelText("Product / Item category *");
    await screen.findByRole("option", { name: "Other (other)" });
    expect(screen.queryByLabelText("Specify garment type *")).toBeNull();

    await userEvent.selectOptions(category, "3");
    expect(await screen.findByLabelText("Specify garment type *")).toBeInTheDocument();

    await userEvent.selectOptions(category, "4");
    expect(screen.queryByLabelText("Specify garment type *")).toBeNull();
  }, 20_000);

  it("shows validation errors without calling the create endpoint", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
      const url = String(input);
      const body = url.includes("product-categories") ? { data: { items: [] } } : { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 }, links: { next: null, prev: null } };
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
