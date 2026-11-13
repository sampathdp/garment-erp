import { describe, expect, it } from "vitest";
import { safeInAppPath } from "./safe-path";

describe("safeInAppPath", () => {
  it("keeps in-app paths with query and hash", () => {
    expect(safeInAppPath("/orders/view/?id=12")).toBe("/orders/view/?id=12");
    expect(safeInAppPath("/notifications/#top")).toBe("/notifications/#top");
  });

  it.each([
    null,
    undefined,
    "",
    "orders/",
    "https://evil.example/",
    "//evil.example/",
    "/\\evil.example/",
    "/\t/evil.example/",
    "/\n/evil.example/",
    "/\r\n//evil.example",
    " /orders/",
    "/%09/evil.example".replace("%09", "\t"),
    "javascript:alert(1)",
    "/" + "a".repeat(2001),
  ])("rejects %j", (value) => {
    expect(safeInAppPath(value)).toBeNull();
  });
});
