import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import type { PlanCategory, ProductionPlan } from "../api";
import { ProductionPlanBoard } from "./plan-board";

const q = (temporary: number, confirmed: number) => ({
  temporary,
  confirmed,
  total: temporary + confirmed,
});

const jersey = (overrides: Partial<PlanCategory> = {}): PlanCategory => ({
  product_type: { id: 1, name: "Jersey" },
  ...q(20, 23),
  pages: [
    {
      page: { id: 7, code: "TSH", name: "T-shirt" },
      is_own: true,
      ...q(20, 0),
    },
  ],
  other_pages: q(0, 23),
  ...overrides,
});

const plan = (overrides: Partial<ProductionPlan> = {}): ProductionPlan => ({
  from: "2026-11-28",
  to: "2027-01-27",
  today: "2026-11-28",
  page_names: "own",
  own_pages: [{ id: 7, code: "TSH", name: "T-shirt" }],
  totals: q(25, 23),
  days: [
    {
      date: "2026-11-20",
      is_overdue: true,
      totals: q(5, 0),
      categories: [
        {
          product_type: { id: 2, name: "Shorts" },
          ...q(5, 0),
          pages: [],
          other_pages: q(5, 0),
        },
      ],
    },
    {
      date: "2026-12-01",
      is_overdue: false,
      totals: q(20, 23),
      categories: [jersey()],
    },
  ],
  ...overrides,
});

describe("ProductionPlanBoard", () => {
  it("shows one card per due date with temporary, confirmed and total per category", () => {
    render(<ProductionPlanBoard plan={plan()} />);

    const day = screen.getByRole("region", { name: "Due 1 Dec 2026" });
    const row = within(day).getByRole("row", { name: /Jersey/ });
    expect(
      within(row).getAllByTitle("Temporary: estimated quantity")[0],
    ).toHaveTextContent("20");
    expect(
      within(row).getAllByTitle("Confirmed: final quantity")[0],
    ).toHaveTextContent("23");
    expect(within(row).getByText("43")).toBeInTheDocument();
    expect(
      within(screen.getByRole("region", { name: "Due 20 Nov 2026" })).getByText(
        "Overdue",
      ),
    ).toBeInTheDocument();
  });

  it("names the viewer's own pages and shows the rest as Other pages", () => {
    render(<ProductionPlanBoard plan={plan()} />);

    const row = within(
      screen.getByRole("region", { name: "Due 1 Dec 2026" }),
    ).getByRole("row", { name: /Jersey/ });
    expect(within(row).getByText("T-shirt (yours)")).toBeInTheDocument();
    expect(within(row).getByText("Other pages")).toBeInTheDocument();
  });

  it("lists every page by name for the full view", () => {
    const all = jersey({
      pages: [
        {
          page: { id: 7, code: "TSH", name: "T-shirt" },
          is_own: false,
          ...q(40, 0),
        },
        {
          page: { id: 8, code: "CTS", name: "Ceylon T-shirt" },
          is_own: false,
          ...q(0, 10),
        },
      ],
      other_pages: null,
      ...q(40, 10),
    });
    render(
      <ProductionPlanBoard
        plan={plan({
          page_names: "all",
          own_pages: [],
          days: [
            {
              date: "2026-12-01",
              is_overdue: false,
              totals: q(40, 10),
              categories: [all],
            },
          ],
        })}
      />,
    );

    expect(screen.getByText("T-shirt")).toBeInTheDocument();
    expect(screen.getByText("Ceylon T-shirt")).toBeInTheDocument();
    expect(screen.queryByText("Other pages")).not.toBeInTheDocument();
  });

  it("says when nothing is due", () => {
    render(<ProductionPlanBoard plan={plan({ days: [], totals: q(0, 0) })} />);
    expect(
      screen.getByText("No orders are due in this period."),
    ).toBeInTheDocument();
  });
});
