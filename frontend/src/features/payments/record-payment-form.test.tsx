import { describe, expect, it } from "vitest";
import { paymentFormSchema } from "./record-payment-form";

const base = { amount: "3,000.00", method: "bank_transfer", bank_name: "Bank of Ceylon (BOC)", reference_no: "BOC-1", payment_date: "2026-11-09", payment_time: "10:30", notes: "", evidence: new File(["x"], "slip.png", { type: "image/png" }) };

describe("paymentFormSchema", () => {
  it("accepts a bank transfer with bank name, reference and slip", () => {
    expect(paymentFormSchema.safeParse(base).success).toBe(true);
  });

  it("requires evidence, bank name and a reference for bank transfers", () => {
    const result = paymentFormSchema.safeParse({ ...base, evidence: null, bank_name: "", reference_no: "" });
    expect(result.error?.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(["evidence", "bank_name", "reference_no"]));
  });

  it("requires a valid payment time for bank transfers (used for duplicate detection)", () => {
    expect(paymentFormSchema.safeParse({ ...base, payment_time: "" }).error?.issues.map((i) => i.path[0])).toContain("payment_time");
    expect(paymentFormSchema.safeParse({ ...base, payment_time: "25:00" }).error?.issues.map((i) => i.path[0])).toContain("payment_time");
  });

  it("allows cash without evidence", () => {
    expect(paymentFormSchema.safeParse({ ...base, method: "cash", evidence: null, reference_no: "", payment_time: "" }).success).toBe(true);
  });

  it("rejects zero amounts and unsupported files", () => {
    const result = paymentFormSchema.safeParse({ ...base, amount: "0", evidence: new File(["x"], "a.exe", { type: "application/x-msdownload" }) });
    expect(result.error?.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(["amount", "evidence"]));
  });
});
