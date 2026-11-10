"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { FormError, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { useToast } from "@/components/ui/toast";
import { applyServerErrors } from "@/lib/forms";
import { formatMoney, parseMoneyToMinor } from "@/lib/format";
import { EVIDENCE_REQUIRED_METHODS, PAYMENT_METHODS, useRecordPayment } from "./api";

const MAX_BYTES = 10 * 1024 * 1024;
const ALLOWED = ["image/jpeg", "image/png", "image/webp", "application/pdf"];

export const paymentFormSchema = z
  .object({
    amount: z.string().trim().refine((v) => (parseMoneyToMinor(v) ?? 0) > 0, "Enter the amount paid"),
    method: z.string().min(1, "Select the payment method"),
    reference_no: z.string().trim().max(80),
    payment_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Select the payment date"),
    notes: z.string().trim().max(500),
    evidence: z.custom<File | null>((v) => v === null || v instanceof File).refine((f) => f === null || f.size <= MAX_BYTES, "The file must be 10 MB or smaller").refine((f) => f === null || ALLOWED.includes(f.type), "Use a JPG, PNG, WebP or PDF file"),
  })
  .superRefine((v, ctx) => {
    if (!EVIDENCE_REQUIRED_METHODS.has(v.method)) return;
    if (v.evidence === null) ctx.addIssue({ code: "custom", path: ["evidence"], message: "Attach the payment slip or screenshot" });
    if (v.reference_no === "") ctx.addIssue({ code: "custom", path: ["reference_no"], message: "Enter the bank or transaction reference" });
  });

type Values = z.infer<typeof paymentFormSchema>;

const today = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Colombo", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());

export function RecordPaymentForm({ orderId, currency, suggestedMinor, onDone }: { orderId: number; currency: string; suggestedMinor: number; onDone: () => void }) {
  const record = useRecordPayment(orderId);
  const { notify } = useToast();
  // One key per form instance: a retry or double click re-sends the same key.
  const [idempotencyKey] = useState(() => crypto.randomUUID());
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(paymentFormSchema),
    defaultValues: { amount: "", method: "bank_transfer", reference_no: "", payment_date: today(), notes: "", evidence: null },
  });
  const errors = form.formState.errors;

  const onSubmit = form.handleSubmit(async (v) => {
    setFormError(null);
    try {
      await record.mutateAsync({
        idempotencyKey,
        input: { amount_minor: parseMoneyToMinor(v.amount) ?? 0, method: v.method, reference_no: v.reference_no, payment_date: v.payment_date, notes: v.notes, evidence: v.evidence },
      });
      notify("Payment recorded. A Second Admin will verify it.", "success");
      onDone();
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError, ["method", "reference_no", "payment_date", "notes", "evidence"]));
      const amountError = error instanceof Error && "fieldErrors" in error ? (error as { fieldErrors: Record<string, string[]> }).fieldErrors.amount_minor?.[0] : undefined;
      if (amountError) form.setError("amount", { type: "server", message: amountError });
    }
  });

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
      <FormError message={formError} />
      <div className="flex items-center justify-between rounded-xl border border-brand/20 bg-brand/5 px-4 py-3 text-xs text-brand">
        <span className="font-medium">Remaining requirement for 30% advance deposit:</span>
        <span className="font-mono text-sm font-bold text-ink">{formatMoney(suggestedMinor, currency)}</span>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label={`Amount (${currency}) *`} inputMode="decimal" placeholder="0.00" error={errors.amount?.message} {...form.register("amount")} />
        <SelectField label="Payment method *" options={PAYMENT_METHODS} error={errors.method?.message} {...form.register("method")} />
        <TextField label="Payment reference" placeholder="e.g. BOC-TX-998231" maxLength={80} error={errors.reference_no?.message} {...form.register("reference_no")} />
        <TextField label="Payment date *" type="date" max={today()} error={errors.payment_date?.message} {...form.register("payment_date")} />
      </div>
      <div className="flex flex-col gap-2 rounded-xl border border-dashed border-line bg-subtle/30 p-4">
        <label htmlFor="payment-evidence" className="text-xs font-semibold uppercase tracking-wider text-muted">
          Payment evidence (slip or screenshot)
        </label>
        <input
          id="payment-evidence"
          type="file"
          accept="image/jpeg,image/png,image/webp,application/pdf"
          aria-invalid={errors.evidence ? true : undefined}
          className="text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink file:shadow-xs hover:file:bg-subtle"
          onChange={(e) => form.setValue("evidence", e.target.files?.[0] ?? null, { shouldValidate: true })}
        />
        {errors.evidence?.message && (
          <p role="alert" className="text-xs text-danger">
            {errors.evidence.message}
          </p>
        )}
      </div>
      <TextAreaField label="Notes" maxLength={500} placeholder="Internal payment remarks or depositor details" error={errors.notes?.message} {...form.register("notes")} />
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="secondary" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" loading={record.isPending}>
          Record payment
        </Button>
      </div>
    </form>
  );
}
