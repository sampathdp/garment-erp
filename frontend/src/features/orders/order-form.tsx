"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { AlertTriangleIcon, CheckCircleIcon, PlusIcon, SearchIcon, UserIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { useToast } from "@/components/ui/toast";
import { type Customer, useCustomers } from "@/features/customers/api";
import { useBranches, usePages } from "@/features/organizations/api";
import { ApiError } from "@/lib/api-client";
import { formatMoney, parseMoneyToMinor } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { useCreateOrder, useOrderTypes, useProductCategories } from "./api";
import { daysUntil, emptyOrderForm, type OrderFormValues, orderFormSchema, SERVER_FIELD_MAP, toCreateOrderInput } from "./order-form-schema";

const URGENT_DAYS = 7;

function CustomerPicker({ pageId, onPick }: { pageId: string; onPick: (c: Customer) => void }) {
  const [search, setSearch] = useState("");
  const term = useDebounced(search.trim());
  const query = useCustomers({ search: term, page_id: pageId ? Number(pageId) : undefined, per_page: 8 }, term.length >= 2 && pageId !== "");

  return (
    <div className="flex flex-col gap-2">
      <TextField
        label="Find an existing customer"
        placeholder={pageId ? "Search by customer name, phone or code…" : "Select a sales page above first"}
        disabled={!pageId}
        icon={<SearchIcon size={16} />}
        maxLength={100}
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        hint="Pick a customer to reuse their details, or fill in a new customer below."
      />
      {term.length >= 2 && query.data && (
        <ul className="divide-y divide-line rounded-xl border border-line bg-surface shadow-xs" aria-label="Matching customers">
          {query.data.data.length === 0 && <li className="px-4 py-3 text-xs text-muted">No matching customer found on this page.</li>}
          {query.data.data.map((c) => (
            <li key={c.id}>
              <button
                type="button"
                className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm hover:bg-subtle/80 transition cursor-pointer"
                onClick={() => {
                  onPick(c);
                  setSearch("");
                }}
              >
                <div>
                  <span className="font-semibold text-ink">{c.name}</span>
                  <span className="ml-2 font-mono text-xs text-muted">({c.customer_code})</span>
                </div>
                <span className="text-xs text-muted">{c.phone}</span>
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
  const pageId = useWatch({ control: form.control, name: "page_id" });
  const dueDate = useWatch({ control: form.control, name: "due_date" });
  const days = daysUntil(dueDate);

  const qty = Number(useWatch({ control: form.control, name: "gross_quantity" })) || 0;
  const unitPrice = parseMoneyToMinor(useWatch({ control: form.control, name: "unit_price" })) ?? 0;
  const deliveryFee = parseMoneyToMinor(useWatch({ control: form.control, name: "delivery_fee" })) ?? 0;
  const discount = parseMoneyToMinor(useWatch({ control: form.control, name: "discount" })) ?? 0;
  const estimatedSubtotal = qty * unitPrice;
  const estimatedTotal = Math.max(0, estimatedSubtotal + deliveryFee - discount);
  const estimatedAdvance = Math.round(estimatedTotal * 0.3);

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
        notify(submit ? `Order ${order.order_no} created` : `Draft ${order.order_no} saved`, "success");
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
        <PageHeader
          title="New order"
          breadcrumb={
            <div className="flex items-center gap-1.5 text-xs text-muted">
              <Link href="/orders/" className="hover:text-ink">Orders</Link>
              <span>/</span>
              <span className="font-semibold text-ink">Intake</span>
            </div>
          }
          description="Garment details, design instructions, attachments and remarks are added after the advance payment is verified."
        />
        <FormError message={formError} />

        {/* Urgent warning banner if due date is < 7 days */}
        {days !== null && days < URGENT_DAYS && days >= 1 && (
          <div className="flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft p-4 text-xs">
            <AlertTriangleIcon size={20} className="shrink-0 text-danger" />
            <div>
              <p className="font-bold text-danger">Urgent Order Detected (Due in {days} working days)</p>
              <p className="mt-0.5 text-danger/80">
                Because this job is required in fewer than 7 working days, it will require dual sign-off from both a Second Admin and an Operation Admin before it can be released into production.
              </p>
            </div>
          </div>
        )}

        {/* Section 1: Page and branch */}
        <Section title="Page and branch" description="Select the sales channel (Facebook Page) and fulfillment branch.">
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label="Page *" options={options(pages.data?.data.filter((p) => p.is_active), "Select page")} error={errors.page_id?.message} {...register("page_id", { onChange: clearPick })} />
            <SelectField label="Branch *" options={options(branches.data?.data.filter((b) => b.is_active), "Select branch")} error={errors.branch_id?.message} {...register("branch_id")} />
          </div>
        </Section>

        {/* Section 2: Customer information */}
        <Section title="Customer information" description="Reuse an existing customer profile or enter new customer contact details.">
          <div className="flex flex-col gap-4">
            {picked ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand/30 bg-brand/5 p-4">
                <div className="flex items-center gap-3">
                  <div className="flex size-10 items-center justify-center rounded-full bg-brand/10 text-brand">
                    <UserIcon size={20} />
                  </div>
                  <div>
                    <p className="text-sm font-bold text-ink">
                      {picked.name} <span className="font-mono text-xs text-muted">({picked.customer_code})</span>
                    </p>
                    <p className="text-xs text-muted">
                      Phone: <span className="font-medium text-ink">{picked.phone}</span> · WhatsApp: {picked.whatsapp ?? "—"} {picked.address ? `· ${picked.address}` : ""}
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
                <div className="grid gap-4 sm:grid-cols-2">
                  <TextField label="Customer name *" maxLength={150} autoComplete="off" placeholder="e.g. Kasun Fernando" error={errors.customer_name?.message} {...register("customer_name")} />
                  <TextField
                    label="Phone number *"
                    type="tel"
                    maxLength={20}
                    placeholder="077 123 4567"
                    error={errors.customer_phone?.message}
                    {...register("customer_phone", { onChange: (e: { target: { value: string } }) => sameAsPhone && setValue("customer_whatsapp", e.target.value) })}
                  />
                  <div className="flex flex-col gap-2">
                    <TextField label="WhatsApp number *" type="tel" maxLength={20} placeholder="077 123 4567" readOnly={sameAsPhone} error={errors.customer_whatsapp?.message} {...register("customer_whatsapp")} />
                    <Checkbox
                      label="Same as phone number"
                      checked={sameAsPhone}
                      onChange={(e) => {
                        setSameAsPhone(e.target.checked);
                        if (e.target.checked) setValue("customer_whatsapp", form.getValues("customer_phone"));
                      }}
                    />
                  </div>
                  <TextField label="Other number" type="tel" maxLength={20} placeholder="Optional secondary contact" error={errors.customer_other_phone?.message} {...register("customer_other_phone")} />
                </div>
                <TextAreaField label="Customer address" maxLength={200} placeholder="Street, city, postal code" error={errors.customer_address?.message} {...register("customer_address")} />
                {duplicate && (
                  <div className="rounded-xl border border-warning/30 bg-warning-soft p-3">
                    <Checkbox
                      label="This is a different person who shares the phone number"
                      description="Otherwise, search and select the existing customer above. Customer accounts are never accidentally merged."
                      {...register("confirm_distinct")}
                    />
                  </div>
                )}
              </>
            )}
            {errors.customer_id?.message && <FormError message={errors.customer_id.message} />}
          </div>
        </Section>

        {/* Section 3: Job specifications */}
        <Section title="Job specifications" description="Primary garment type, target completion date, and ordered volume.">
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label="Order type *" options={options(orderTypes.data, "Select order type")} error={errors.order_type_id?.message} {...register("order_type_id")} />
            <TextField
              label="Due date *"
              type="date"
              error={errors.due_date?.message}
              hint={days === null ? undefined : days < 1 ? "The due date must be after today." : days < URGENT_DAYS ? `Due in ${days} working day(s) — Urgent rules apply!` : `Due in ${days} days.`}
              {...register("due_date")}
            />
            <SelectField label="Product / item category *" options={options(categories.data, "Select category")} error={errors.product_type_id?.message} {...register("product_type_id")} />
            <TextField label="Gross quantity *" inputMode="numeric" maxLength={6} placeholder="e.g. 50" error={errors.gross_quantity?.message} {...register("gross_quantity")} />
          </div>
        </Section>

        {/* Section 4: Invoice & Pricing Calculation */}
        <Section title="Invoice" description="Unit pricing and charges. 30% advance is automatically calculated upon submission.">
          <div className="grid gap-4 sm:grid-cols-3">
            <TextField label="Unit price (LKR) *" inputMode="decimal" placeholder="0.00" error={errors.unit_price?.message} {...register("unit_price")} />
            <TextField label="Delivery fee (LKR)" inputMode="decimal" placeholder="Page default" error={errors.delivery_fee?.message} {...register("delivery_fee")} />
            <TextField label="Discount (LKR)" inputMode="decimal" placeholder="0.00" error={errors.discount?.message} {...register("discount")} />
          </div>

          {/* Real-time Calculation Summary Card */}
          {estimatedSubtotal > 0 && (
            <div className="mt-5 rounded-xl border border-line/80 bg-subtle/50 p-4">
              <div className="flex flex-wrap items-center justify-between gap-4 text-xs">
                <div>
                  <span className="text-muted">Estimated Subtotal: </span>
                  <span className="font-semibold text-ink">{formatMoney(estimatedSubtotal, "LKR")}</span>
                </div>
                <div>
                  <span className="text-muted">Estimated Total: </span>
                  <span className="font-bold text-ink">{formatMoney(estimatedTotal, "LKR")}</span>
                </div>
                <div className="flex items-center gap-1.5 rounded-lg bg-brand/10 px-3 py-1 font-bold text-brand">
                  <span>30% Advance Required: </span>
                  <span>{formatMoney(estimatedAdvance, "LKR")}</span>
                </div>
              </div>
            </div>
          )}
        </Section>

        {/* Form Actions */}
        <div className="flex flex-wrap items-center justify-end gap-3 pt-2">
          <Button variant="secondary" onClick={() => router.push("/orders/")}>
            Cancel
          </Button>
          <Button variant="secondary" loading={create.isPending} onClick={() => void save(false)}>
            Save draft
          </Button>
          <Button type="submit" loading={create.isPending}>
            Create order
          </Button>
        </div>
      </form>
    </RequirePermission>
  );
}
