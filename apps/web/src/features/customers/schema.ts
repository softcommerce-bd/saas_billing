import { z } from "zod";
import { isValidAmount } from "@/lib/money";
import { normalizeBangladeshMobile } from "@/lib/phone";

const optionalText = (max: number) => z.string().trim().max(max).optional().or(z.literal(""));
const optionalAmount = z
  .string()
  .trim()
  .optional()
  .refine((v) => !v || isValidAmount(v), "Enter an amount like 1500 or 1500.50.");

export const customerSchema = z.object({
  type: z.enum(["individual", "business"]),
  name: z.string().trim().min(1, "Enter the customer's name.").max(160),
  company_name: optionalText(160),
  mobile: z
    .string()
    .trim()
    .optional()
    .refine((v) => !v || normalizeBangladeshMobile(v) !== null, "Enter a valid Bangladesh mobile number, e.g. 01712-345678."),
  secondary_phone: optionalText(20),
  email: z.string().trim().email("Enter a valid email address.").optional().or(z.literal("")),
  billing_address: optionalText(1000),
  shipping_address: optionalText(1000),
  district: optionalText(60),
  bin: optionalText(32),
  tin: optionalText(32),
  opening_balance: optionalAmount,
  credit_limit: optionalAmount,
  payment_terms_days: z
    .string()
    .trim()
    .optional()
    .refine((v) => !v || (/^\d+$/.test(v) && Number(v) <= 365), "Enter days between 0 and 365."),
  notes: optionalText(5000),
  status: z.enum(["active", "inactive"]),
  preferred_language: z.enum(["bn", "en"]),
  sms_opt_in: z.boolean(),
  email_opt_in: z.boolean(),
});

export type CustomerFormValues = z.infer<typeof customerSchema>;

/** Converts form strings to the API payload: empty strings become null. */
export function toCustomerPayload(values: CustomerFormValues): Record<string, unknown> {
  const blankToNull = (v: string | undefined) => (v === undefined || v.trim() === "" ? null : v.trim());
  return {
    ...values,
    company_name: blankToNull(values.company_name),
    mobile: blankToNull(values.mobile),
    secondary_phone: blankToNull(values.secondary_phone),
    email: blankToNull(values.email),
    billing_address: blankToNull(values.billing_address),
    shipping_address: blankToNull(values.shipping_address),
    district: blankToNull(values.district),
    bin: blankToNull(values.bin),
    tin: blankToNull(values.tin),
    opening_balance: blankToNull(values.opening_balance) ?? "0",
    credit_limit: blankToNull(values.credit_limit),
    payment_terms_days: values.payment_terms_days ? Number(values.payment_terms_days) : null,
    notes: blankToNull(values.notes),
  };
}

export const emptyCustomer: CustomerFormValues = {
  type: "individual",
  name: "",
  company_name: "",
  mobile: "",
  secondary_phone: "",
  email: "",
  billing_address: "",
  shipping_address: "",
  district: "",
  bin: "",
  tin: "",
  opening_balance: "",
  credit_limit: "",
  payment_terms_days: "",
  notes: "",
  status: "active",
  preferred_language: "bn",
  sms_opt_in: true,
  email_opt_in: true,
};
