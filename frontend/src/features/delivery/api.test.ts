import { describe, expect, it } from "vitest";
import { deliveryStatusTone, requiredDocuments } from "./api";
import { cellText, headerText } from "@/features/reports/reports-screen";
import { safeLink } from "@/features/notifications/notifications-screen";

describe("requiredDocuments", () => {
  it("mirrors the server rule per delivery method", () => {
    expect(requiredDocuments("courier")).toEqual(["waybill"]);
    expect(requiredDocuments("own_vehicle")).toEqual(["delivery_sheet"]);
    expect(requiredDocuments("pickup")).toEqual(["delivery_photo", "customer_photo"]);
    expect(requiredDocuments(null)).toEqual([]);
  });
});

describe("deliveryStatusTone", () => {
  it("shows completed jobs as success", () => {
    expect(deliveryStatusTone("delivered", true)).toBe("success");
    expect(deliveryStatusTone("ready", false)).toBe("warning");
  });
});

describe("report cells", () => {
  it("renders money, labels, booleans and empty values", () => {
    expect(cellText({ formatted: "LKR 1,000.00" })).toBe("LKR 1,000.00");
    expect(cellText({ label: "Printing" })).toBe("Printing");
    expect(cellText(true)).toBe("Yes");
    expect(cellText(null)).toBe("—");
    expect(cellText(12)).toBe("12");
    expect(headerText("average_hours")).toBe("Average hours");
  });
});

describe("notification deep links", () => {
  it("follows only in-app paths", () => {
    expect(safeLink("/cutting/view/?id=3")).toBe("/cutting/view/?id=3");
    expect(safeLink("//evil.example")).toBeNull();
    expect(safeLink("https://evil.example")).toBeNull();
    expect(safeLink(null)).toBeNull();
  });
});
