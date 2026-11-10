import { describe, expect, it } from "vitest";
import { formatBusinessDate, formatDateTime, formatMoney, minorToInput, parseMoneyToMinor } from "./format";

describe("format", () => {
  it("formats integer minor units as LKR", () => {
    expect(formatMoney(123456)).toMatch(/1,234\.56/);
    expect(formatMoney(0)).toMatch(/0\.00/);
  });

  it("shows UTC timestamps in Asia/Colombo (UTC+05:30)", () => {
    expect(formatDateTime("2026-11-08T20:00:00Z")).toMatch(/9 Nov 2026/);
    expect(formatDateTime("2026-11-08T20:00:00Z")).toMatch(/01:30/);
  });

  it("does not shift business dates across timezones", () => {
    expect(formatBusinessDate("2026-12-31")).toMatch(/31 Dec 2026/);
    expect(formatBusinessDate(null)).toBe("—");
  });
});

describe("money input", () => {
  it("parses amounts to minor units without floating point", () => {
    expect(parseMoneyToMinor("350")).toBe(35000);
    expect(parseMoneyToMinor("1,234.5")).toBe(123450);
    expect(parseMoneyToMinor("0.07")).toBe(7);
    expect(parseMoneyToMinor("12.345")).toBeNull();
    expect(parseMoneyToMinor("-5")).toBeNull();
    expect(parseMoneyToMinor("abc")).toBeNull();
  });

  it("formats minor units for editing", () => {
    expect(minorToInput(35000)).toBe("350.00");
    expect(minorToInput(7)).toBe("0.07");
  });
});
