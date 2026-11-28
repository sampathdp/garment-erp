import { cn } from "@/lib/cn";
import { formatBusinessDate } from "@/lib/format";
import type {
  PlanCategory,
  PlanDay,
  PlanQuantities,
  ProductionPlan,
} from "../api";

const weekday = (date: string) => {
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: "UTC",
    weekday: "short",
  }).format(new Date(Date.UTC(y, m - 1, d)));
};

/** Yellow = temporary (estimate), red = confirmed (design completed, list locked). */
export function QuantityChip({
  kind,
  value,
  label,
}: {
  kind: "temporary" | "confirmed";
  value: number;
  label?: string;
}) {
  return (
    <span
      className={cn(
        "inline-flex min-w-10 items-center justify-center gap-1 rounded-md border px-2 py-0.5 text-xs font-semibold tabular-nums",
        kind === "temporary"
          ? "border-yellow-400/60 bg-yellow-100 text-yellow-900"
          : "border-red-500/50 bg-red-100 text-red-800",
        value === 0 && "opacity-40",
      )}
      title={
        kind === "temporary"
          ? "Temporary: estimated quantity"
          : "Confirmed: final quantity"
      }
    >
      {label && <span className="font-normal">{label}</span>}
      {value}
    </span>
  );
}

export function PlanLegend() {
  return (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted">
      <span className="flex items-center gap-2">
        <span aria-hidden className="size-3 rounded-sm bg-yellow-400" />
        <span>
          <strong className="text-ink">Temporary</strong>: estimated quantity
          entered when the order was created. Design or name/size list not final
          yet.
        </span>
      </span>
      <span className="flex items-center gap-2">
        <span aria-hidden className="size-3 rounded-sm bg-red-500" />
        <span>
          <strong className="text-ink">Confirmed</strong>: design completed and
          final list confirmed. Exact quantity, locked in.
        </span>
      </span>
    </div>
  );
}

/** Share of temporary vs confirmed pieces, scaled against the busiest category of the range. */
function LoadBar({ q, max }: { q: PlanQuantities; max: number }) {
  const pct = (n: number) => (max > 0 ? `${(n / max) * 100}%` : "0%");
  return (
    <div
      aria-hidden
      className="flex h-2 w-full min-w-16 overflow-hidden rounded-full bg-subtle"
    >
      <span className="bg-red-500" style={{ width: pct(q.confirmed) }} />
      <span className="bg-yellow-400" style={{ width: pct(q.temporary) }} />
    </div>
  );
}

function Breakdown({ category }: { category: PlanCategory }) {
  const parts: { key: string; label: string; q: PlanQuantities }[] =
    category.pages.map((p) => ({
      key: `p${p.page.id}`,
      label: p.is_own ? `${p.page.name} (yours)` : p.page.name,
      q: p,
    }));
  if (category.other_pages && category.other_pages.total > 0)
    parts.push({
      key: "others",
      label: "Other pages",
      q: category.other_pages,
    });
  if (parts.length === 0) return <span className="text-muted">—</span>;

  return (
    <ul className="flex flex-col gap-1">
      {parts.map((part) => (
        <li key={part.key} className="flex flex-wrap items-center gap-1.5">
          <span className="min-w-0 truncate text-ink">{part.label}</span>
          <span className="tabular-nums text-muted">{part.q.total}</span>
          {part.q.temporary > 0 && (
            <QuantityChip kind="temporary" value={part.q.temporary} />
          )}
          {part.q.confirmed > 0 && (
            <QuantityChip kind="confirmed" value={part.q.confirmed} />
          )}
        </li>
      ))}
    </ul>
  );
}

function DayCard({
  day,
  pageNames,
  max,
}: {
  day: PlanDay;
  pageNames: ProductionPlan["page_names"];
  max: number;
}) {
  return (
    <section
      aria-label={`Due ${formatBusinessDate(day.date)}`}
      className={cn(
        "overflow-hidden rounded-xl border bg-surface shadow-xs",
        day.is_overdue ? "border-danger/40" : "border-line",
      )}
    >
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-subtle/60 px-4 py-2.5">
        <div className="flex items-baseline gap-2">
          <h3 className="text-sm font-semibold text-ink">
            {formatBusinessDate(day.date)}
          </h3>
          <span className="text-xs text-muted">{weekday(day.date)}</span>
          {day.is_overdue && (
            <span className="rounded-full bg-danger-soft px-2 py-0.5 text-[11px] font-medium text-danger-strong">
              Overdue
            </span>
          )}
        </div>
        <div className="flex items-center gap-2 text-xs">
          <QuantityChip kind="temporary" value={day.totals.temporary} />
          <QuantityChip kind="confirmed" value={day.totals.confirmed} />
          <span className="font-semibold tabular-nums text-ink">
            {day.totals.total} pcs
          </span>
        </div>
      </header>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <caption className="sr-only">
            Quantities due {formatBusinessDate(day.date)} by category
          </caption>
          <thead className="text-left text-xs text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Category</th>
              <th className="px-2 py-2 text-center font-medium">Temporary</th>
              <th className="px-2 py-2 text-center font-medium">Confirmed</th>
              <th className="px-2 py-2 text-right font-medium">Total</th>
              <th className="hidden w-40 px-4 py-2 font-medium sm:table-cell">
                <span className="sr-only">Load</span>
              </th>
              <th className="px-4 py-2 font-medium">
                {pageNames === "all" ? "Pages" : "Your pages / others"}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {day.categories.map((c) => (
              <tr key={c.product_type.id} className="align-top">
                <td className="px-4 py-2 font-medium text-ink">
                  {c.product_type.name}
                </td>
                <td className="px-2 py-2 text-center">
                  <QuantityChip kind="temporary" value={c.temporary} />
                </td>
                <td className="px-2 py-2 text-center">
                  <QuantityChip kind="confirmed" value={c.confirmed} />
                </td>
                <td className="px-2 py-2 text-right font-semibold tabular-nums text-ink">
                  {c.total}
                </td>
                <td className="hidden px-4 py-3 sm:table-cell">
                  <LoadBar q={c} max={max} />
                </td>
                <td className="px-4 py-2 text-xs">
                  <Breakdown category={c} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

/** One card per due date (chronological, overdue first), one row per product category. */
export function ProductionPlanBoard({ plan }: { plan: ProductionPlan }) {
  if (plan.days.length === 0) {
    return (
      <p className="rounded-xl border border-dashed border-line p-8 text-center text-sm text-muted">
        No orders are due in this period.
      </p>
    );
  }
  const max = Math.max(
    1,
    ...plan.days.flatMap((d) => d.categories.map((c) => c.total)),
  );

  return (
    <div className="flex flex-col gap-4">
      {plan.days.map((day) => (
        <DayCard
          key={day.date}
          day={day}
          pageNames={plan.page_names}
          max={max}
        />
      ))}
    </div>
  );
}
