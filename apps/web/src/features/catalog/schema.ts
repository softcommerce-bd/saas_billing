import { z } from "zod";
import { isValidAmount, minorToDecimalString } from "@/lib/money";
import type { CatalogItem } from "@/lib/api/types";

export const UNITS = ["piece", "unit", "box", "kg", "hour", "day", "month", "project", "service"] as const;

const percent = z
  .string()
  .trim()
  .optional()
  .refine((v) => !v || (/^\d{1,3}(\.\d{1,2})?$/.test(v) && Number(v) <= 100), "Enter a percentage between 0 and 100.");

export const catalogSchema = z
  .object({
    type: z.enum(["product", "service"]),
    name: z.string().trim().min(1, "Enter a name.").max(160),
    description: z.string().trim().max(2000).optional(),
    sku: z.string().trim().max(60).optional(),
    category: z.string().trim().max(80).optional(),
    unit: z.string().trim().min(1, "Choose a unit."),
    custom_unit: z.string().trim().max(30).optional(),
    price: z.string().trim().refine(isValidAmount, "Enter a price like 1500 or 1500.50."),
    default_discount_percent: percent,
    tax_rate_percent: percent,
    tax_label: z.string().trim().max(40).optional(),
    is_active: z.boolean(),
  })
  .refine((v) => v.unit !== "custom" || (v.custom_unit && v.custom_unit.length > 0), { path: ["custom_unit"], message: "Enter the custom unit." });

export type CatalogFormValues = z.infer<typeof catalogSchema>;

export const emptyCatalogItem: CatalogFormValues = {
  type: "product", name: "", description: "", sku: "", category: "", unit: "piece", custom_unit: "", price: "",
  default_discount_percent: "", tax_rate_percent: "", tax_label: "", is_active: true,
};

const bpToPercent = (bp: number | null) => (bp === null || bp === 0 ? "" : minorToDecimalString(bp).replace(/\.00$/, ""));

export function catalogToForm(item: CatalogItem): CatalogFormValues {
  const standard = (UNITS as readonly string[]).includes(item.unit);
  return {
    type: item.type,
    name: item.name,
    description: item.description ?? "",
    sku: item.sku ?? "",
    category: item.category ?? "",
    unit: standard ? item.unit : "custom",
    custom_unit: standard ? "" : item.unit,
    price: item.price,
    default_discount_percent: bpToPercent(item.default_discount_bp),
    tax_rate_percent: bpToPercent(item.tax_rate_bp),
    tax_label: item.tax_label ?? "",
    is_active: item.is_active,
  };
}

export function toCatalogPayload(v: CatalogFormValues): Record<string, unknown> {
  const blank = (s?: string) => (s && s.trim() !== "" ? s.trim() : null);
  return {
    type: v.type,
    name: v.name,
    description: blank(v.description),
    sku: blank(v.sku),
    category: blank(v.category),
    unit: v.unit === "custom" ? v.custom_unit : v.unit,
    price: v.price,
    default_discount_percent: blank(v.default_discount_percent) ?? "0",
    tax_rate_percent: blank(v.tax_rate_percent),
    tax_label: blank(v.tax_label),
    is_active: v.is_active,
  };
}
