import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ApiError, type Paginated } from "@/lib/api-client";
import { DataTable, type Column } from "./data-table";

interface Row {
  id: number;
  name: string;
}

const columns: Column<Row>[] = [{ key: "name", header: "Name", sortKey: "name", cell: (r) => r.name }];

const page = (rows: Row[], meta: Partial<Paginated<Row>["meta"]> = {}): Paginated<Row> => ({
  data: rows,
  meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, ...meta },
  links: { next: null, prev: null },
});

describe("DataTable", () => {
  it("shows loading, empty and error states", () => {
    const { rerender } = render(<DataTable columns={columns} result={undefined} isLoading error={null} rowKey={(r) => r.id} />);
    expect(screen.getByRole("status")).toBeInTheDocument();

    rerender(<DataTable columns={columns} result={page([])} isLoading={false} error={null} rowKey={(r) => r.id} emptyTitle="No rows" />);
    expect(screen.getByText("No rows")).toBeInTheDocument();

    rerender(<DataTable columns={columns} result={undefined} isLoading={false} error={new ApiError(403, "forbidden", "x", {}, "req-9")} rowKey={(r) => r.id} />);
    expect(screen.getByRole("alert")).toHaveTextContent("You do not have permission to view this.");
    expect(screen.getByText(/req-9/)).toBeInTheDocument();
  });

  it("renders rows and asks the server to sort and paginate", async () => {
    const onSort = vi.fn();
    const onPage = vi.fn();
    render(
      <DataTable columns={columns} result={page([{ id: 1, name: "Alpha" }], { last_page: 3, total: 60 })} isLoading={false} error={null} rowKey={(r) => r.id} sort="name" onSortChange={onSort} onPageChange={onPage} />,
    );

    expect(screen.getByText("Alpha")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /Name/ }));
    expect(onSort).toHaveBeenCalledWith("-name");
    await userEvent.click(screen.getByRole("button", { name: "Next" }));
    expect(onPage).toHaveBeenCalledWith(2);
    expect(screen.getByRole("button", { name: "Previous" })).toBeDisabled();
  });
});
