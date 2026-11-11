import { describe, expect, it } from "vitest";
import { cutQuantityError, cutTotals, cuttingStatusTone } from "./api";
import { issueBody, issueInputError, issueStatusTone, type IssueInput } from "@/features/issues/api";

const input = (over: Partial<IssueInput> = {}): IssueInput => ({ category: "damaged_pieces", description: "Torn panel", quantity_affected: 2, part: "", size: "", image: null, ...over });

describe("cutQuantityError", () => {
  it("accepts whole numbers from 0 up to the expected quantity", () => {
    expect(cutQuantityError("0", 6)).toBeNull();
    expect(cutQuantityError("6", 6)).toBeNull();
  });

  it("rejects empty, negative, decimal and over-expected values", () => {
    expect(cutQuantityError("", 6)).toBe("Enter a quantity.");
    expect(cutQuantityError("-1", 6)).toContain("whole number");
    expect(cutQuantityError("1.5", 6)).toContain("whole number");
    expect(cutQuantityError("7", 6)).toBe("Cannot exceed the expected 6.");
  });
});

describe("cutTotals", () => {
  it("adds cut and missing items", () => {
    expect(cutTotals([{ expected: 4, cut: 4 }, { expected: 6, cut: 2 }])).toEqual({ expected: 10, cut: 6, missing: 4 });
  });
});

describe("status tones", () => {
  it("maps cutting and issue statuses", () => {
    expect(cuttingStatusTone("completed")).toBe("success");
    expect(cuttingStatusTone("pending_assignment")).toBe("warning");
    expect(issueStatusTone("open")).toBe("danger");
    expect(issueStatusTone("resolved")).toBe("success");
  });
});

describe("issueInputError", () => {
  it("accepts a complete piece report", () => {
    expect(issueInputError(input())).toBeNull();
    expect(issueInputError(input({ category: "machine", quantity_affected: null }))).toBeNull();
  });

  it("mirrors the server rules", () => {
    expect(issueInputError(input({ description: " " }))).toBe("Describe the issue.");
    expect(issueInputError(input({ quantity_affected: null }))).toContain("quantity affected");
    expect(issueInputError(input({ quantity_affected: 0 }))).toContain("quantity affected");
    expect(issueInputError(input({ category: "missing_parts" }))).toBe("Choose the missing part.");
    expect(issueInputError(input({ category: "missing_parts", part: "sleeve" }))).toBeNull();
    expect(issueInputError(input({ image: new File(["x"], "a.pdf", { type: "application/pdf" }) }))).toContain("JPG, PNG or WebP");
  });
});

describe("issueBody", () => {
  it("builds the multipart report with only the given fields", () => {
    const body = issueBody(input({ size: "M", description: "  Torn  " }), 12);
    expect(body.get("order_id")).toBe("12");
    expect(body.get("category")).toBe("damaged_pieces");
    expect(body.get("description")).toBe("Torn");
    expect(body.get("quantity_affected")).toBe("2");
    expect(body.get("size")).toBe("M");
    expect(body.has("part")).toBe(false);
    expect(body.has("image")).toBe(false);
  });
});
