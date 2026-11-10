import { describe, expect, it } from "vitest";
import { bulkStatusTone, isAllowedTransferLink, measurementError, missingLines, submissionBody } from "./api";

const bulkMeter = { label: "Bulk Meter", decimals: 2, min_value: "0.01", max_value: "10000.00", unit: "m" };

describe("measurementError", () => {
  it("accepts positive decimals within range and the configured precision", () => {
    expect(measurementError(bulkMeter, "12.5")).toBeNull();
    expect(measurementError(bulkMeter, "12.50")).toBeNull();
    expect(measurementError(bulkMeter, "")).toBeNull();
  });

  it("rejects negatives, text, too many decimals and out-of-range values", () => {
    expect(measurementError(bulkMeter, "-1")).toBe("Bulk Meter must be a positive number.");
    expect(measurementError(bulkMeter, "abc")).toBe("Bulk Meter must be a positive number.");
    expect(measurementError(bulkMeter, "1e3")).toBe("Bulk Meter must be a positive number.");
    expect(measurementError(bulkMeter, "12.555")).toBe("Bulk Meter can have at most 2 decimal place(s).");
    expect(measurementError(bulkMeter, "0")).toContain("must be between");
    expect(measurementError(bulkMeter, "20000")).toContain("must be between");
  });
});

describe("isAllowedTransferLink", () => {
  it("only accepts https WeTransfer links without credentials or custom ports", () => {
    expect(isAllowedTransferLink("https://wetransfer.com/downloads/abc/def")).toBe(true);
    expect(isAllowedTransferLink("https://we.tl/t-abc123")).toBe(true);
    expect(isAllowedTransferLink("http://wetransfer.com/downloads/abc")).toBe(false);
    expect(isAllowedTransferLink("https://wetransfer.com.evil.example/x")).toBe(false);
    expect(isAllowedTransferLink("https://user:pw@wetransfer.com/x")).toBe(false);
    expect(isAllowedTransferLink("https://wetransfer.com:8443/x")).toBe(false);
    expect(isAllowedTransferLink("not a url")).toBe(false);
  });
});

describe("submissionBody", () => {
  it("sends the optimistic-lock version, files and links as multipart fields", () => {
    const pdf = new File(["%PDF-1.4"], "final.pdf", { type: "application/pdf" });
    const body = submissionBody(7, { preview: null, files: [pdf], transfer_url: "https://we.tl/t-1", notes: "" });
    expect(body.get("version")).toBe("7");
    expect(body.get("files[0]")).toBeInstanceOf(File);
    expect(body.get("transfer_url")).toBe("https://we.tl/t-1");
    expect(body.has("notes")).toBe(false);
    expect(body.has("preview")).toBe(false);
  });
});

describe("helpers", () => {
  it("flattens missing production data and maps statuses to badge tones", () => {
    expect(missingLines({ measurements: ["Bulk Meter is required."], checklist: ["Not completed: Sizes"] })).toEqual(["Bulk Meter is required.", "Not completed: Sizes"]);
    expect(missingLines([])).toEqual([]);
    expect(bulkStatusTone("completed")).toBe("success");
    expect(bulkStatusTone("changes_requested")).toBe("danger");
    expect(bulkStatusTone("pending_assignment")).toBe("warning");
  });
});
