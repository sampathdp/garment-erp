"use client";

import type { ReactNode } from "react";
import type { Paginated } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { Button } from "./button";
import { ArrowUpDownIcon, ChevronLeftIcon, ChevronRightIcon } from "./icons";
import { EmptyState, ErrorState, LoadingState } from "./states";

export interface Column<T> {
  key: string;
  header: string;
  cell: (row: T) => ReactNode;
  /** Server-side sort key (must be whitelisted by the API). */
  sortKey?: string;
  className?: string;
}

export interface DataTableProps<T> {
  columns: Column<T>[];
  result: Paginated<T> | undefined;
  isLoading: boolean;
  error: unknown;
  onRetry?: () => void;
  rowKey: (row: T) => string | number;
  sort?: string;
  onSortChange?: (sort: string) => void;
  onPageChange?: (page: number) => void;
  emptyTitle?: string;
  emptyDescription?: string;
  caption?: string;
}

/**
 * Server-driven table: sorting and pagination are API query params; no client-side
 * filtering of business data. Handles loading, error and empty states.
 */
export function DataTable<T>({
  columns,
  result,
  isLoading,
  error,
  onRetry,
  rowKey,
  sort,
  onSortChange,
  onPageChange,
  emptyTitle = "Nothing here yet",
  emptyDescription,
  caption,
}: DataTableProps<T>) {
  if (error) return <ErrorState error={error} onRetry={onRetry} />;
  if (isLoading || !result) return <LoadingState />;
  if (result.data.length === 0) return <EmptyState title={emptyTitle} description={emptyDescription} />;

  const sortField = sort?.replace(/^-/, "");
  const sortDesc = sort?.startsWith("-") ?? false;

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
        <table className="w-full min-w-[36rem] border-collapse text-left text-sm">
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead className="border-b border-line bg-subtle/60 text-xs font-semibold uppercase tracking-wider text-muted">
            <tr>
              {columns.map((col) => {
                const active = col.sortKey !== undefined && col.sortKey === sortField;
                return (
                  <th
                    key={col.key}
                    scope="col"
                    className={cn("px-4 py-3 font-semibold", col.className)}
                    aria-sort={active ? (sortDesc ? "descending" : "ascending") : undefined}
                  >
                    {col.sortKey && onSortChange ? (
                      <button
                        type="button"
                        className={cn(
                          "inline-flex items-center gap-1.5 transition-colors cursor-pointer select-none",
                          active ? "text-brand font-bold" : "hover:text-ink text-muted",
                        )}
                        onClick={() => onSortChange(active && !sortDesc ? `-${col.sortKey}` : col.sortKey!)}
                      >
                        <span>{col.header}</span>
                        {active ? (
                          <span aria-hidden className="font-bold text-xs">{sortDesc ? "↓" : "↑"}</span>
                        ) : (
                          <ArrowUpDownIcon size={13} className="opacity-40" />
                        )}
                      </button>
                    ) : (
                      col.header
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody className="divide-y divide-line/70">
            {result.data.map((row) => (
              <tr key={rowKey(row)} className="transition-colors hover:bg-subtle/50 group">
                {columns.map((col) => (
                  <td key={col.key} className={cn("px-4 py-3 text-ink align-middle", col.className)}>
                    {col.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {onPageChange && result.meta.last_page > 1 && (
        <nav aria-label="Pagination" className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-1 py-1 text-sm text-muted">
          <span className="text-xs font-medium text-muted">
            Showing page <strong className="font-semibold text-ink">{result.meta.page}</strong> of{" "}
            <strong className="font-semibold text-ink">{result.meta.last_page}</strong> · <strong className="font-semibold text-ink">{result.meta.total}</strong> total records
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              icon={<ChevronLeftIcon size={14} />}
              disabled={result.meta.page <= 1}
              onClick={() => onPageChange(result.meta.page - 1)}
            >
              Previous
            </Button>
            <Button
              variant="secondary"
              size="sm"
              disabled={result.meta.page >= result.meta.last_page}
              onClick={() => onPageChange(result.meta.page + 1)}
            >
              Next
              <ChevronRightIcon size={14} />
            </Button>
          </div>
        </nav>
      )}
    </div>
  );
}
