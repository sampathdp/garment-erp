/** Display helpers. Values come from the API; nothing here calculates business amounts. */

export const DISPLAY_TIMEZONE = "Asia/Colombo";

/** Money arrives as integer minor units (cents). */
export function formatMoney(amountMinor: number, currency = "LKR"): string {
  return new Intl.NumberFormat("en-LK", { style: "currency", currency, minimumFractionDigits: 2 }).format(amountMinor / 100);
}

/** "1,234.50" -> 123450 minor units without floating point; null when the text is not an amount. */
export function parseMoneyToMinor(text: string): number | null {
  const clean = text.replace(/,/g, "").trim();
  const match = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(clean);
  if (!match) return null;
  return Number(match[1]) * 100 + Number((match[2] ?? "").padEnd(2, "0"));
}

/** 123450 -> "1234.50" for editing in a text input. */
export function minorToInput(amountMinor: number): string {
  return `${Math.trunc(amountMinor / 100)}.${String(amountMinor % 100).padStart(2, "0")}`;
}

/** ISO-8601 UTC timestamp -> Asia/Colombo date and time. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("en-GB", { timeZone: DISPLAY_TIMEZONE, dateStyle: "medium", timeStyle: "short" }).format(new Date(iso));
}

/** YYYY-MM-DD business date (already a Colombo calendar date) -> display; no timezone shift. */
export function formatBusinessDate(date: string | null | undefined): string {
  if (!date) return "—";
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", dateStyle: "medium" }).format(new Date(Date.UTC(y, m - 1, d)));
}
