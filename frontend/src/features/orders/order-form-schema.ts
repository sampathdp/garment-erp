import { z } from "zod";
import { parseMoneyToMinor } from "@/lib/format";
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
    .refine((v) => (v === "" ? !required : parseMoneyToMinor(v) !== null), { message: required ? `Enter the ${label.toLowerCase()}` : `Enter a valid ${label.toLowerCase()}` });

export const orderFormSchema = z.object({
  branch_id: z.string().min(1, "Select the branch"),
  page_id: z.string().min(1, "Select the page"),
  customer_id: z.string(),
  customer_name: z.string().trim().max(150),
  customer_phone: z.string().trim().max(20),
  customer_whatsapp: z.string().trim().max(20),
  customer_other_phone: z.string().trim().max(20).refine((v) => v === "" || PHONE.test(v), "Enter a valid phone number"),
  customer_address: z.string().trim().max(200),
  confirm_distinct: z.boolean(),
  order_type_id: z.string().min(1, "Select the order type"),
  due_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Select the due date"),
  product_type_id: z.string().min(1, "Select the product / item category"),
  gross_quantity: z.string().trim().regex(/^[1-9]\d{0,5}$/, "Enter the gross quantity (1 or more)"),
  unit_price: money("Unit price", true),
  delivery_fee: money("Delivery fee", false),
  discount: money("Discount", false),
}).superRefine((v, ctx) => {
  if (v.customer_id !== "") return; // existing customer selected
  if (v.customer_name.length < 2) ctx.addIssue({ code: "custom", path: ["customer_name"], message: "Customer name is required" });
  for (const [key, label] of [["customer_phone", "Phone number"], ["customer_whatsapp", "WhatsApp number"]] as const) {
    const result = phone(label).safeParse(v[key]);
    if (!result.success) ctx.addIssue({ code: "custom", path: [key], message: result.error.issues[0]?.message ?? "Invalid" });
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
  customer_address: "",
  confirm_distinct: false,
  order_type_id: "",
  due_date: "",
  product_type_id: "",
  gross_quantity: "",
  unit_price: "",
  delivery_fee: "",
  discount: "",
};

/** Maps the form to the API body. Totals and the advance are NOT sent: the server calculates them. */
export function toCreateOrderInput(v: OrderFormValues, submit: boolean): CreateOrderInput {
  const base: CreateOrderInput = {
    branch_id: Number(v.branch_id),
    page_id: Number(v.page_id),
    order_type_id: Number(v.order_type_id),
    due_date: v.due_date,
    product_type_id: Number(v.product_type_id),
    gross_quantity: Number(v.gross_quantity),
    unit_price_minor: parseMoneyToMinor(v.unit_price) ?? 0,
    delivery_fee_minor: v.delivery_fee === "" ? null : parseMoneyToMinor(v.delivery_fee),
    discount_minor: v.discount === "" ? 0 : (parseMoneyToMinor(v.discount) ?? 0),
    submit,
  };
  if (v.customer_id !== "") return { ...base, customer_id: Number(v.customer_id) };
  return {
    ...base,
    customer: {
      name: v.customer_name,
      phone: v.customer_phone,
      whatsapp: v.customer_whatsapp,
      other_phone: v.customer_other_phone || null,
      address: v.customer_address || null,
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
  "customer.address": "customer_address",
  order_type_id: "order_type_id",
  due_date: "due_date",
  product_type_id: "product_type_id",
  gross_quantity: "gross_quantity",
  unit_price_minor: "unit_price",
  delivery_fee_minor: "delivery_fee",
  discount_minor: "discount",
};
