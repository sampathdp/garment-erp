"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import {
  AlertTriangleIcon,
  BuildingIcon,
  CalendarIcon,
  CheckCircleIcon,
  CheckIcon,
  CreditCardIcon,
  FileTextIcon,
  GlobeIcon,
  PhoneIcon,
  SearchIcon,
  ShirtIcon,
  SparklesIcon,
  UploadCloudIcon,
  UserIcon,
} from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { useToast } from "@/components/ui/toast";
import { type Customer, useCustomers } from "@/features/customers/api";
import { useBranches, usePages } from "@/features/organizations/api";
import { PAYMENT_METHODS } from "@/features/payments/api";
import { ApiError, apiRequest } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatMoney, parseMoneyToMinor } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { useCreateOrder, useOrderTypes, useProductCategories } from "./api";
import { daysUntil, emptyOrderForm, type OrderFormValues, orderFormSchema, SERVER_FIELD_MAP, toCreateOrderInput } from "./order-form-schema";

const URGENT_DAYS = 7;

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function CustomerPicker({ pageId, onPick }: { pageId: string; onPick: (c: Customer) => void }) {
  const [search, setSearch] = useState("");
  const term = useDebounced(search.trim());
  const query = useCustomers({ search: term, page_id: pageId ? Number(pageId) : undefined, per_page: 8 }, term.length >= 2 && pageId !== "");

  return (
    <div className="flex flex-col gap-2">
      <TextField
        label="Search existing customers"
        placeholder={pageId ? "Search by customer name, phone number or customer code…" : "Select a sales page above first to search"}
        disabled={!pageId}
        icon={<SearchIcon size={16} />}
        maxLength={100}
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        hint="Search customer directory to reuse verified client contact details."
      />
      {term.length >= 2 && query.data && (
        <ul className="divide-y divide-line rounded-xl border border-line bg-surface shadow-md overflow-hidden" aria-label="Matching customers">
          {query.data.data.length === 0 && (
            <li className="px-4 py-3.5 text-xs text-muted flex items-center justify-between">
              <span>No matching customer found on this page.</span>
              <span className="text-[11px] font-medium text-brand">Enter new customer below ↓</span>
            </li>
          )}
          {query.data.data.map((c) => (
            <li key={c.id}>
              <button
                type="button"
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm hover:bg-brand/5 transition cursor-pointer group"
                onClick={() => {
                  onPick(c);
                  setSearch("");
                }}
              >
                <div className="flex items-center gap-3 min-w-0">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand group-hover:bg-brand group-hover:text-white transition">
                    {c.name.slice(0, 2).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-ink truncate">{c.name}</span>
                      <span className="rounded bg-subtle px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted">
                        {c.customer_code}
                      </span>
                    </div>
                    <p className="text-xs text-muted truncate">{c.address || "No address on file"}</p>
                  </div>
                </div>
                <div className="text-right shrink-0">
                  <span className="text-xs font-medium text-ink block">{c.phone}</span>
                  <span className="text-[11px] text-brand font-medium group-hover:underline">Select client →</span>
                </div>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function OrderForm() {
  const router = useRouter();
  const { notify } = useToast();
  const create = useCreateOrder();
  const branches = useBranches({ per_page: 100, sort: "name" });
  const pages = usePages({ per_page: 100, sort: "name" });
  const orderTypes = useOrderTypes();
  const categories = useProductCategories();
  const [formError, setFormError] = useState<string | null>(null);
  const [duplicate, setDuplicate] = useState(false);
  const [sameAsPhone, setSameAsPhone] = useState(true);
  const [picked, setPicked] = useState<Customer | null>(null);

  const form = useForm<OrderFormValues>({ resolver: zodResolver(orderFormSchema), defaultValues: emptyOrderForm });
  const { register, formState, setValue, setError } = form;
  const errors = formState.errors;

  // Auto-set branch_id in the background since branches are hidden from the user
  useEffect(() => {
    if (!form.getValues("branch_id")) {
      const activeBranch = branches.data?.data?.find((b) => b.is_active) ?? branches.data?.data?.[0];
      setValue("branch_id", String(activeBranch?.id ?? "1"));
    }
  }, [branches.data, setValue, form]);

  // Real-time watchers for dynamic summary & calculations
  const pageId = useWatch({ control: form.control, name: "page_id" });
  const branchId = useWatch({ control: form.control, name: "branch_id" });
  const orderTypeId = useWatch({ control: form.control, name: "order_type_id" });
  const productTypeId = useWatch({ control: form.control, name: "product_type_id" });
  const dueDate = useWatch({ control: form.control, name: "due_date" });
  const grossQuantity = useWatch({ control: form.control, name: "gross_quantity" });
  const advanceAmount = useWatch({ control: form.control, name: "advance_amount" });
  const customerName = useWatch({ control: form.control, name: "customer_name" });
  const customerPhone = useWatch({ control: form.control, name: "customer_phone" });
  const paymentEvidence = useWatch({ control: form.control, name: "payment_evidence" });
  const paymentMethod = useWatch({ control: form.control, name: "payment_method" });

  const days = daysUntil(dueDate);
  const isUrgent = days !== null && days < URGENT_DAYS && days >= 1;

  // Selected object metadata for summary card
  const selectedPage = pages.data?.data.find((p) => String(p.id) === pageId);
  const selectedOrderType = orderTypes.data?.find((t) => String(t.id) === orderTypeId);
  const selectedCategory = categories.data?.find((c) => String(c.id) === productTypeId);

  // Financial calculations
  const qty = Math.max(0, parseInt(grossQuantity || "0", 10) || 0);
  const enteredAdvanceMinor = parseMoneyToMinor(advanceAmount || "") ?? 0;

  const formattedDueDate = dueDate
    ? new Date(dueDate + "T00:00:00").toLocaleDateString("en-GB", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : null;

  const options = (items: { id: number; name: string; code?: string }[] | undefined, placeholder: string) => [
    { value: "", label: placeholder },
    ...(items ?? []).map((i) => ({ value: String(i.id), label: i.code ? `${i.name} (${i.code})` : i.name })),
  ];

  const pick = (c: Customer) => {
    setPicked(c);
    setValue("customer_id", String(c.id));
    setDuplicate(false);
  };

  const clearPick = () => {
    setPicked(null);
    setValue("customer_id", "");
  };

  const save = (submit: boolean) =>
    form.handleSubmit(async (values) => {
      setFormError(null);
      const input = toCreateOrderInput(values, submit);
      try {
        const order = await create.mutateAsync(input);

        // Record the advance payment directly so it does not need to be done inside the order
        const advMinor = parseMoneyToMinor(values.advance_amount) ?? 0;
        if (advMinor > 0) {
          try {
            const formData = new FormData();
            formData.set("amount_minor", String(advMinor));
            formData.set("method", values.payment_method);
            formData.set("payment_date", values.payment_date);
            if (values.payment_reference) formData.set("reference_no", values.payment_reference);
            if (values.payment_notes) formData.set("notes", values.payment_notes);
            if (values.payment_evidence) formData.set("evidence", values.payment_evidence);

            await apiRequest<{ data: unknown }>(`orders/${order.id}/payments`, {
              method: "POST",
              body: formData,
              headers: { "Idempotency-Key": crypto.randomUUID() },
            });
            notify(
              `Order ${order.order_no} created and advance payment of ${formatMoney(advMinor, order.currency)} recorded for Second Admin verification.`,
              "success",
            );
          } catch (payErr) {
            notify(
              `Order ${order.order_no} created, but recording advance payment failed: ${
                payErr instanceof Error ? payErr.message : "Error"
              }`,
              "error",
            );
          }
        } else {
          notify(submit ? `Order ${order.order_no} created` : `Draft ${order.order_no} saved`, "success");
        }

        router.push(`/orders/view/?id=${order.id}`);
      } catch (error) {
        if (!(error instanceof ApiError)) return setFormError("Something went wrong. Please try again.");
        if (error.code === "possible_duplicate_customer") setDuplicate(true);
        let unmatched: string | null = null;
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          const target = SERVER_FIELD_MAP[field];
          if (target) setError(target, { type: "server", message: messages[0] });
          else unmatched ??= messages[0] ?? null;
        }
        setFormError(error.isValidation && unmatched === null ? (error.code === "possible_duplicate_customer" ? error.message : null) : (unmatched ?? error.message));
      }
    })();

  return (
    <RequirePermission anyOf={["orders.create"]}>
      <form noValidate className="flex flex-col gap-6" onSubmit={(e) => { e.preventDefault(); void save(true); }}>
        {/* Executive Header with Process Breadcrumbs & Phase Indicator */}
        <div className="flex flex-col gap-3 pb-2 border-b border-line sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div className="flex items-center gap-2 text-xs font-medium text-muted">
              <Link href="/orders/" className="hover:text-ink transition">Orders</Link>
              <span>/</span>
              <span className="font-semibold text-ink">New Order Intake</span>
            </div>
            <h1 className="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">New Order Intake</h1>
            <p className="mt-1 text-xs text-muted sm:text-sm">
              Capture customer profile, garment specifications, and collect advance deposit to authorize production release.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <div className="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-brand/5 px-3 py-1 text-xs font-semibold text-brand">
              <SparklesIcon size={14} />
              <span>Phase 1 of 4: Intake & Deposit</span>
            </div>
          </div>
        </div>

        <FormError message={formError} />

        {/* Dynamic Urgent Production Warning Banner */}
        {isUrgent && (
          <div className="flex items-start gap-3.5 rounded-2xl border border-danger/40 bg-gradient-to-r from-danger-soft via-danger-soft/50 to-surface p-4 shadow-xs">
            <div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-danger/20 text-danger">
              <AlertTriangleIcon size={20} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-bold text-danger">Urgent Order Priority Flagged</p>
                <span className="rounded-full bg-danger px-2.5 py-0.5 text-[10px] font-bold text-white uppercase tracking-wider">
                  Due in {days} working days
                </span>
              </div>
              <p className="mt-1 text-xs text-danger/90 leading-relaxed">
                Because this job deadline is fewer than 7 working days away, the system will enforce mandatory dual sign-off from both a <strong>Second Admin</strong> and an <strong>Operations Admin</strong> before manufacturing release.
              </p>
            </div>
          </div>
        )}

        {/* 2-Column Responsive Layout: Left Form (8 cols) + Right Sticky Summary (4 cols) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
          {/* Main Intake Form Column */}
          <div className="lg:col-span-8 flex flex-col gap-6 min-w-0">
            {/* Section 1: Company / Shop */}
            <Section
              title="Company / Shop"
              description="Select the company or retail store receiving this customer order."
            >
              <div className="max-w-xl">
                <SelectField
                  label="Company / Shop *"
                  options={[
                    { value: "", label: "--Select Company / Shop--" },
                    ...(pages.data?.data.filter((p) => p.is_active) ?? []).map((p) => ({
                      value: String(p.id),
                      label: p.name,
                    })),
                  ]}
                  error={errors.page_id?.message}
                  {...register("page_id", { onChange: clearPick })}
                />
              </div>

              {selectedPage && (
                <div className="mt-3.5 flex items-center gap-2 rounded-xl bg-subtle/50 px-3.5 py-2 text-xs text-muted border border-line/60">
                  <GlobeIcon size={14} className="text-brand shrink-0" />
                  <span>
                    Selected Company / Shop: <strong className="text-ink">{selectedPage.name}</strong>
                  </span>
                </div>
              )}
            </Section>

            {/* Section 2: Customer Information */}
            <Section
              title="Customer information"
              description="Lookup existing registered clients or capture contact details for a new customer."
            >
              <div className="flex flex-col gap-4">
                {picked ? (
                  <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-brand/40 bg-brand/5 p-4 transition-all">
                    <div className="flex items-center gap-3.5 min-w-0">
                      <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand text-white font-bold text-sm shadow-xs">
                        {picked.name.slice(0, 2).toUpperCase()}
                      </div>
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="text-sm font-bold text-ink">{picked.name}</p>
                          <span className="rounded bg-brand/10 px-2 py-0.5 font-mono text-xs font-semibold text-brand">
                            {picked.customer_code}
                          </span>
                          <span className="rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success-strong">
                            Verified Client
                          </span>
                        </div>
                        <p className="mt-0.5 text-xs text-muted">
                          Phone: <strong className="text-ink">{picked.phone}</strong>
                          {picked.whatsapp && <> · WhatsApp: <strong className="text-ink">{picked.whatsapp}</strong></>}
                          {picked.address && <> · {picked.address}</>}
                        </p>
                      </div>
                    </div>
                    <Button variant="secondary" size="sm" onClick={clearPick}>
                      Change customer
                    </Button>
                  </div>
                ) : (
                  <>
                    <CustomerPicker pageId={pageId} onPick={pick} />

                    <div className="relative my-1 text-center">
                      <div className="absolute inset-0 flex items-center">
                        <div className="w-full border-t border-line/80" />
                      </div>
                      <span className="relative bg-surface px-3 text-[11px] font-semibold uppercase tracking-wider text-muted">
                        Or Register New Customer
                      </span>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <TextField
                        label="Customer name *"
                        maxLength={150}
                        autoComplete="off"
                        placeholder="e.g. Kasun Fernando"
                        error={errors.customer_name?.message}
                        {...register("customer_name")}
                      />
                      <TextField
                        label="Phone number *"
                        type="tel"
                        maxLength={20}
                        placeholder="077 123 4567"
                        error={errors.customer_phone?.message}
                        {...register("customer_phone", {
                          onChange: (e: { target: { value: string } }) => {
                            if (sameAsPhone) setValue("customer_whatsapp", e.target.value);
                          },
                        })}
                      />
                      <div className="flex flex-col gap-2">
                        <TextField
                          label="WhatsApp number *"
                          type="tel"
                          maxLength={20}
                          placeholder="077 123 4567"
                          readOnly={sameAsPhone}
                          error={errors.customer_whatsapp?.message}
                          {...register("customer_whatsapp")}
                        />
                        <Checkbox
                          label="Same as phone number"
                          checked={sameAsPhone}
                          onChange={(e) => {
                            setSameAsPhone(e.target.checked);
                            if (e.target.checked) setValue("customer_whatsapp", form.getValues("customer_phone"));
                          }}
                        />
                      </div>
                      <TextField
                        label="Other contact number"
                        type="tel"
                        maxLength={20}
                        placeholder="Optional secondary phone or landline"
                        error={errors.customer_other_phone?.message}
                        {...register("customer_other_phone")}
                      />
                    </div>

                    <TextAreaField
                      label="Delivery / Customer address"
                      maxLength={200}
                      placeholder="Street address, city, postal code"
                      error={errors.customer_address?.message}
                      {...register("customer_address")}
                    />

                    {duplicate && (
                      <div className="rounded-xl border border-warning/40 bg-warning-soft/60 p-3.5">
                        <Checkbox
                          label="This is a distinct individual sharing this phone number"
                          description="Otherwise, search and select the existing customer account above to preserve order history."
                          {...register("confirm_distinct")}
                        />
                      </div>
                    )}
                  </>
                )}
                {errors.customer_id?.message && <FormError message={errors.customer_id.message} />}
              </div>
            </Section>

            {/* Section 3: Job Specifications */}
            <Section
              title="Job specifications"
              description="Primary garment category, production technique, gross volume, and scheduled delivery target."
            >
              <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                  label="Product / Item category *"
                  options={options(categories.data, "Select garment category")}
                  error={errors.product_type_id?.message}
                  {...register("product_type_id")}
                />
                <SelectField
                  label="Order type / Technique *"
                  options={options(orderTypes.data, "Select order type")}
                  error={errors.order_type_id?.message}
                  {...register("order_type_id")}
                />
                <TextField
                  label="Gross quantity (pcs) *"
                  inputMode="numeric"
                  maxLength={6}
                  placeholder="e.g. 50"
                  error={errors.gross_quantity?.message}
                  {...register("gross_quantity")}
                />
                <TextField
                  label="Target due date *"
                  type="date"
                  error={errors.due_date?.message}
                  hint={
                    days === null
                      ? undefined
                      : days < 1
                      ? "Target date must be scheduled after today."
                      : days < URGENT_DAYS
                      ? `Due in ${days} working day(s) — Urgent rules apply!`
                      : `Due in ${days} calendar days.`
                  }
                  {...register("due_date")}
                />
              </div>

              {days !== null && days >= URGENT_DAYS && (
                <div className="mt-3.5 flex items-center gap-2 rounded-xl bg-success-soft/50 px-3.5 py-2 text-xs text-success-strong border border-success/30">
                  <CheckCircleIcon size={14} className="shrink-0" />
                  <span>Standard Production Lead Time · Eligible for standard single-admin approval.</span>
                </div>
              )}
            </Section>

            {/* Section 4: Advance Payment */}
            <Section
              title="Advance Payment"
              description="Record customer advance payment deposit. An advance payment is required to release the job to production."
            >
              <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                  label="Advance amount (LKR) *"
                  inputMode="decimal"
                  placeholder="e.g. 6,000.00"
                  error={errors.advance_amount?.message}
                  {...register("advance_amount")}
                />
                <SelectField
                  label="Payment method *"
                  options={PAYMENT_METHODS}
                  error={errors.payment_method?.message}
                  {...register("payment_method")}
                />
                <TextField
                  label="Bank / Transaction reference"
                  maxLength={80}
                  placeholder="e.g. BOC-TX-998231"
                  error={errors.payment_reference?.message}
                  {...register("payment_reference")}
                />
                <TextField
                  label="Payment date *"
                  type="date"
                  error={errors.payment_date?.message}
                  {...register("payment_date")}
                />
              </div>

                {/* Evidence Dropzone */}
                <div className="flex flex-col gap-2">
                  <span className="text-xs font-semibold tracking-wide text-ink">
                    Payment slip / Transfer proof
                  </span>
                  {paymentEvidence ? (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-line bg-surface p-3.5 shadow-xs">
                      <div className="flex items-center gap-3 min-w-0">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                          <FileTextIcon size={20} />
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-xs font-semibold text-ink">{paymentEvidence.name}</p>
                          <p className="text-[11px] text-muted">
                            {formatFileSize(paymentEvidence.size)} · <span className="text-success font-semibold">Ready to upload</span>
                          </p>
                        </div>
                      </div>
                      <button
                        type="button"
                        onClick={() => setValue("payment_evidence", null, { shouldValidate: true })}
                        className="rounded-lg px-2.5 py-1 text-xs font-medium text-danger hover:bg-danger-soft transition cursor-pointer"
                      >
                        Remove
                      </button>
                    </div>
                  ) : (
                    <label
                      htmlFor="advance-evidence"
                      className="group flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-line/80 bg-subtle/20 p-6 text-center hover:border-brand/50 hover:bg-subtle/40 transition cursor-pointer"
                    >
                      <div className="flex size-10 items-center justify-center rounded-full bg-surface border border-line text-muted group-hover:text-brand group-hover:scale-105 transition">
                        <UploadCloudIcon size={20} />
                      </div>
                      <div>
                        <span className="text-xs font-semibold text-ink group-hover:text-brand">Click to upload payment slip or receipt</span>
                        <p className="text-[11px] text-muted mt-0.5">JPEG, PNG, WebP or PDF (up to 10 MB)</p>
                      </div>
                      <input
                        id="advance-evidence"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        className="sr-only"
                        onChange={(e) => {
                          const file = e.target.files?.[0] ?? null;
                          setValue("payment_evidence", file, { shouldValidate: true });
                        }}
                      />
                    </label>
                  )}
                  {errors.payment_evidence?.message && (
                    <p role="alert" className="text-xs text-danger font-medium">
                      {String(errors.payment_evidence.message)}
                    </p>
                  )}
                </div>

                <TextAreaField
                  label="Payment notes"
                  maxLength={500}
                  placeholder="Optional internal remarks regarding this deposit"
                  error={errors.payment_notes?.message}
                  {...register("payment_notes")}
                />
            </Section>
          </div>

          {/* Sticky Summary & Command Sidebar (4 cols) */}
          <div className="lg:col-span-4 sticky top-20 flex flex-col gap-6">
            <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col gap-5">
              {/* Header */}
              <div className="flex items-center justify-between border-b border-line/70 pb-3">
                <div className="flex items-center gap-2">
                  <div className="flex size-7 items-center justify-center rounded-lg bg-brand/10 text-brand">
                    <ShirtIcon size={16} />
                  </div>
                  <h3 className="text-sm font-bold text-ink">Order Summary</h3>
                </div>
                <span className="rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-semibold text-brand">
                  Live Preview
                </span>
              </div>

              {/* Order Specs Snapshot */}
              <div className="flex flex-col gap-2.5 text-xs">
                <div className="flex items-center justify-between">
                  <span className="text-muted">Company / Shop:</span>
                  <span className="font-semibold text-ink text-right truncate max-w-[170px]">
                    {selectedPage?.name ?? "—"}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted">Customer:</span>
                  <span className="font-semibold text-ink text-right truncate max-w-[170px]">
                    {picked ? picked.name : customerName ? customerName : "Pending"}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted">Garment:</span>
                  <span className="font-semibold text-ink text-right truncate max-w-[170px]">
                    {selectedCategory?.name ?? "—"}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted">Technique:</span>
                  <span className="font-semibold text-ink text-right truncate max-w-[170px]">
                    {selectedOrderType?.name ?? "—"}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted">Quantity:</span>
                  <span className="font-bold text-ink">{qty > 0 ? `${qty} pcs` : "—"}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted">Due date:</span>
                  <span className="font-semibold text-ink">
                    {formattedDueDate ?? "—"}
                    {isUrgent && <span className="ml-1.5 text-danger font-bold text-[11px]">(Urgent)</span>}
                  </span>
                </div>
              </div>

              {/* Advance Payment Deposit Summary */}
              <div className="rounded-xl border border-line bg-subtle/40 p-3.5 flex flex-col gap-2.5 text-xs">
                <div className="flex items-center justify-between text-muted">
                  <span>Advance Deposit:</span>
                  <span className="font-mono text-ink font-bold text-sm">
                    {enteredAdvanceMinor > 0 ? formatMoney(enteredAdvanceMinor, "LKR") : "—"}
                  </span>
                </div>
                <div className="flex items-center justify-between text-muted">
                  <span>Payment Method:</span>
                  <span className="font-medium text-ink capitalize">
                    {paymentMethod ? paymentMethod.replace(/_/g, " ") : "Bank Transfer"}
                  </span>
                </div>
                {paymentEvidence && (
                  <div className="flex items-center justify-between text-muted">
                    <span>Payment Slip:</span>
                    <span className="text-success font-medium">Attached ✓</span>
                  </div>
                )}
                <div className="border-t border-line/80 pt-2 flex items-center justify-between text-[11px] text-muted">
                  <span>Verification:</span>
                  <span className="text-brand font-semibold">Requires 2nd Admin</span>
                </div>
              </div>

              {/* Advance Deposit Status Indicator */}
              <div className="flex flex-col gap-1.5">
                {enteredAdvanceMinor > 0 ? (
                  <div className="flex items-center gap-1.5 rounded-lg bg-success-soft/70 px-3 py-2 text-xs font-semibold text-success-strong border border-success/30">
                    <CheckIcon size={14} className="shrink-0" />
                    <span>Advance payment recorded ({formatMoney(enteredAdvanceMinor, "LKR")})</span>
                  </div>
                ) : (
                  <div className="rounded-lg bg-subtle/50 px-3 py-2 text-xs text-muted border border-line/60">
                    Enter the advance payment amount to proceed with order intake.
                  </div>
                )}
              </div>

              {/* Main Actions */}
              <div className="flex flex-col gap-2 pt-2 border-t border-line/70">
                <Button type="submit" size="lg" className="w-full font-semibold" loading={create.isPending}>
                  Create order
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  size="md"
                  className="w-full"
                  loading={create.isPending}
                  onClick={() => void save(false)}
                >
                  Save draft
                </Button>
                <Link
                  href="/orders/"
                  className="mt-1 text-center text-xs text-muted hover:text-ink transition"
                >
                  Cancel and return
                </Link>
              </div>

              {/* Workflow Stepper Information */}
              <div className="rounded-xl bg-subtle/30 p-3 text-[11px] text-muted flex flex-col gap-1.5 border border-line/50">
                <div className="font-semibold text-ink">Intake Pipeline:</div>
                <div className="flex items-center gap-2 text-brand font-medium">
                  <span className="size-1.5 rounded-full bg-brand" />
                  <span>1. Intake & Deposit (Current)</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="size-1.5 rounded-full bg-muted/40" />
                  <span>2. Second Admin Verification</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="size-1.5 rounded-full bg-muted/40" />
                  <span>3. Garment Roster & Artwork</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="size-1.5 rounded-full bg-muted/40" />
                  <span>4. Factory Production Release</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </RequirePermission>
  );
}
