import { z } from "zod";
import { parseMoneyToMinor } from "@/lib/format";
import { EVIDENCE_REQUIRED_METHODS } from "@/features/payments/api";
import type { CreateOrderInput } from "./api";

/** Sri Lankan local (0771234567) or international (+94771234567) numbers; the API normalises to E.164. */
const PHONE = /^(\+|00)?[0-9][0-9\s\-().]{7,18}$/;

const phone = (label: string) =>
  z
    .string()
    .trim()
    .min(1, `${label} is required`)
    .max(20)
    .regex(PHONE, `Enter a valid ${label.toLowerCase()}`);

const money = (label: string, required: boolean) =>
  z
    .string()
    .trim()
    .refine((v) => (v === "" ? !required : parseMoneyToMinor(v) !== null), {
      message: required ? `Enter the ${label.toLowerCase()}` : `Enter a valid ${label.toLowerCase()}`,
    });

const todayColombo = () =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Colombo",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());

const MAX_BYTES = 10 * 1024 * 1024;
const ALLOWED_MIME = ["image/jpeg", "image/png", "image/webp", "application/pdf"];

export const orderFormSchema = z
  .object({
    branch_id: z.string().min(1, "Select the branch"),
    page_id: z.string().min(1, "Select the page"),
    customer_id: z.string(),
    customer_name: z.string().trim().max(150),
    customer_phone: z.string().trim().max(20),
    customer_whatsapp: z.string().trim().max(20),
    customer_other_phone: z.string().trim().max(20).refine((v) => v === "" || PHONE.test(v), "Enter a valid phone number"),
    customer_address_line1: z.string().trim().max(200),
    customer_address_line2: z.string().trim().max(200),
    customer_street: z.string().trim().max(200),
    customer_city: z.string().trim().max(80),
    customer_district: z.string().trim().max(80),
    customer_address: z.string().trim().max(200),
    confirm_distinct: z.boolean(),
    due_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Select the due date"),
    product_type_id: z.string().min(1, "Select the product / item category"),
    gross_quantity: z.string().trim().regex(/^[1-9]\d{0,5}$/, "Enter the gross quantity (1 or more)"),
    unit_price: z.string().trim(),
    delivery_fee: money("Delivery fee", false),
    discount: money("Discount", false),
    advance_amount: z.string().trim(),
    payment_method: z.string().min(1, "Select the payment method"),
    payment_bank: z.string().trim().max(80),
    payment_reference: z.string().trim().max(80),
    payment_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Select the payment date"),
    payment_time: z.string().refine((v) => v === "" || /^([01]\d|2[0-3]):[0-5]\d$/.test(v), "Enter the time as HH:MM"),
    payment_notes: z.string().trim().max(500),
    payment_evidence: z
      .custom<File | null>((v) => v === null || v instanceof File)
      .refine((f) => f === null || f.size <= MAX_BYTES, "The file must be 10 MB or smaller")
      .refine((f) => f === null || ALLOWED_MIME.includes(f.type), "Use a JPG, PNG, WebP or PDF file"),
  })
  .superRefine((v, ctx) => {
    if (v.customer_id === "") {
      if (v.customer_name.length < 2) {
        ctx.addIssue({ code: "custom", path: ["customer_name"], message: "Customer name is required" });
      }
      for (const [key, label] of [["customer_phone", "Phone number"], ["customer_whatsapp", "WhatsApp number"]] as const) {
        const result = phone(label).safeParse(v[key]);
        if (!result.success) {
          ctx.addIssue({ code: "custom", path: [key], message: result.error.issues[0]?.message ?? "Invalid" });
        }
      }
      if (v.customer_city === "") {
        ctx.addIssue({ code: "custom", path: ["customer_city"], message: "Main town is required" });
      }
      if (v.customer_district === "") {
        ctx.addIssue({ code: "custom", path: ["customer_district"], message: "District is required" });
      }
    }

    const advMinor = parseMoneyToMinor(v.advance_amount) ?? 0;

    if (v.unit_price === "" && advMinor <= 0) {
      ctx.addIssue({ code: "custom", path: ["unit_price"], message: "Enter the unit price" });
      ctx.addIssue({ code: "custom", path: ["advance_amount"], message: "Enter the advance payment amount" });
    } else if (v.advance_amount !== "" && advMinor <= 0) {
      ctx.addIssue({ code: "custom", path: ["advance_amount"], message: "Enter a valid advance amount" });
    }

    if (advMinor > 0 && EVIDENCE_REQUIRED_METHODS.has(v.payment_method)) {
      if (v.payment_bank === "") {
        ctx.addIssue({ code: "custom", path: ["payment_bank"], message: "Select the bank" });
      }
      if (v.payment_time === "") {
        ctx.addIssue({ code: "custom", path: ["payment_time"], message: "Enter the time of the payment shown on the slip" });
      }
      if (v.payment_reference === "") {
        ctx.addIssue({ code: "custom", path: ["payment_reference"], message: "Enter the transaction or deposit reference" });
      }
      if (v.payment_evidence === null) {
        ctx.addIssue({ code: "custom", path: ["payment_evidence"], message: "Attach the payment slip or screenshot" });
      }
    }
  });

export type OrderFormValues = z.infer<typeof orderFormSchema>;

export const emptyOrderForm: OrderFormValues = {
  branch_id: "",
  page_id: "",
  customer_id: "",
  customer_name: "",
  customer_phone: "",
  customer_whatsapp: "",
  customer_other_phone: "",
  customer_address_line1: "",
  customer_address_line2: "",
  customer_street: "",
  customer_city: "",
  customer_district: "",
  customer_address: "",
  confirm_distinct: false,
  due_date: "",
  product_type_id: "",
  gross_quantity: "",
  unit_price: "",
  delivery_fee: "",
  discount: "",
  advance_amount: "",
  payment_method: "bank_transfer",
  payment_bank: "",
  payment_reference: "",
  payment_date: todayColombo(),
  payment_time: "",
  payment_notes: "",
  payment_evidence: null,
};

/** Maps the form to the API body. Totals and the advance are NOT sent: the server calculates them. */
export function toCreateOrderInput(v: OrderFormValues, submit: boolean): CreateOrderInput {
  const qty = Number(v.gross_quantity) || 1;
  const advMinor = parseMoneyToMinor(v.advance_amount) ?? 0;
  let unitPriceMinor = parseMoneyToMinor(v.unit_price) ?? 0;

  if (unitPriceMinor <= 0 && advMinor > 0) {
    // 30% advance rule: advance_required = ceil(total * 0.30)
    // To satisfy advance requirement exactly: total = advance / 0.30
    unitPriceMinor = Math.max(1, Math.round(advMinor / (qty * 0.30)));
  }

  const base: CreateOrderInput = {
    branch_id: Number(v.branch_id),
    page_id: Number(v.page_id),
    due_date: v.due_date,
    product_type_id: Number(v.product_type_id),
    gross_quantity: Number(v.gross_quantity),
    unit_price_minor: unitPriceMinor,
    delivery_fee_minor: v.delivery_fee === "" ? null : parseMoneyToMinor(v.delivery_fee),
    discount_minor: v.discount === "" ? 0 : (parseMoneyToMinor(v.discount) ?? 0),
    submit,
  };
  if (v.customer_id !== "") return { ...base, customer_id: Number(v.customer_id) };
  const formattedAddress = [v.customer_address_line1, v.customer_address_line2, v.customer_street, v.customer_city, v.customer_district].filter(Boolean).join(", ") || v.customer_address || null;
  return {
    ...base,
    customer: {
      name: v.customer_name,
      phone: v.customer_phone,
      whatsapp: v.customer_whatsapp,
      other_phone: v.customer_other_phone || null,
      address_line1: v.customer_address_line1 || null,
      address_line2: v.customer_address_line2 || null,
      street: v.customer_street || null,
      city: v.customer_city || null,
      district: v.customer_district || null,
      address: formattedAddress,
      ...(v.confirm_distinct ? { confirm_distinct: true } : {}),
    },
  };
}

/** Calendar days from today (Asia/Colombo) to the due date. A hint only: the server applies the rule with the working calendar. */
export function daysUntil(dueDate: string, today: Date = new Date()): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dueDate)) return null;
  const colombo = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Colombo", year: "numeric", month: "2-digit", day: "2-digit" }).format(today);
  const [y1, m1, d1] = colombo.split("-").map(Number);
  const [y2, m2, d2] = dueDate.split("-").map(Number);
  return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86_400_000);
}

/** API field names (422 errors) → form field names. */
export const SERVER_FIELD_MAP: Record<string, keyof OrderFormValues> = {
  branch_id: "branch_id",
  page_id: "page_id",
  customer_id: "customer_id",
  "customer.name": "customer_name",
  "customer.phone": "customer_phone",
  "customer.whatsapp": "customer_whatsapp",
  "customer.other_phone": "customer_other_phone",
  "customer.address_line1": "customer_address_line1",
  "customer.address_line2": "customer_address_line2",
  "customer.street": "customer_street",
  "customer.city": "customer_city",
  "customer.district": "customer_district",
  "customer.address": "customer_address",
  due_date: "due_date",
  product_type_id: "product_type_id",
  gross_quantity: "gross_quantity",
  unit_price_minor: "unit_price",
  delivery_fee_minor: "delivery_fee",
  discount_minor: "discount",
  bank_name: "payment_bank",
};

/**
 * Default page for a new order: the user's owned page first, else their first assigned page
 * (active pages only). Users with all-page access and no assigned page get a default only when
 * there is exactly one active page. Returns null when the user must choose.
 */
export function defaultPageId(userPages: { id: number; is_owner: boolean }[], pages: { id: number; is_active: boolean }[]): number | null {
  const active = pages.filter((p) => p.is_active);
  const activeIds = new Set(active.map((p) => p.id));
  const mine = userPages.filter((p) => activeIds.has(p.id));
  return (mine.find((p) => p.is_owner) ?? mine[0] ?? (active.length === 1 ? active[0] : undefined))?.id ?? null;
}
