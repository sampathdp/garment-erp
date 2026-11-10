import { describe, expect, it } from "vitest";
import { paymentFormSchema } from "./record-payment-form";

const base = { amount: "3,000.00", method: "bank_transfer", reference_no: "BOC-1", payment_date: "2026-11-09", notes: "", evidence: new File(["x"], "slip.png", { type: "image/png" }) };

describe("paymentFormSchema", () => {
  it("accepts a bank transfer with reference and slip", () => {
    expect(paymentFormSchema.safeParse(base).success).toBe(true);
  });

  it("requires evidence and a reference for bank transfers", () => {
    const result = paymentFormSchema.safeParse({ ...base, evidence: null, reference_no: "" });
    expect(result.error?.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(["evidence", "reference_no"]));
  });

  it("allows cash without evidence", () => {
    expect(paymentFormSchema.safeParse({ ...base, method: "cash", evidence: null, reference_no: "" }).success).toBe(true);
  });

  it("rejects zero amounts and unsupported files", () => {
    const result = paymentFormSchema.safeParse({ ...base, amount: "0", evidence: new File(["x"], "a.exe", { type: "application/x-msdownload" }) });
    expect(result.error?.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(["amount", "evidence"]));
  });
});
