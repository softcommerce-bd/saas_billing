"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { applyApiErrors } from "@/lib/forms";
import { useWriteAccess } from "@/lib/session/SessionProvider";
import { writeBlockedMessage } from "@/components/subscription/WriteGuard";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { CheckboxField, SelectField, TextAreaField, TextField } from "@/components/ui/Field";
import { catalogSchema, emptyCatalogItem, toCatalogPayload, UNITS, type CatalogFormValues } from "./schema";

export function CatalogForm({ initial, onSubmit, submitLabel }: { initial?: CatalogFormValues; onSubmit: (payload: Record<string, unknown>) => Promise<unknown>; submitLabel: string }) {
  const access = useWriteAccess("catalog.manage");
  const [formError, setFormError] = useState<string | null>(null);
  const { register, handleSubmit, setError, control, formState } = useForm<CatalogFormValues>({ resolver: zodResolver(catalogSchema), defaultValues: initial ?? emptyCatalogItem });
  const unit = useWatch({ control, name: "unit" });
  const errors = formState.errors;

  async function submit(values: CatalogFormValues) {
    setFormError(null);
    try {
      await onSubmit(toCatalogPayload(values));
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  return (
    <form onSubmit={handleSubmit(submit)} noValidate className="space-y-6">
      {!access.allowed && <Alert tone="warning">{writeBlockedMessage(access.reason)}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}
      <fieldset disabled={!access.allowed} className="grid gap-4 sm:grid-cols-2">
        <SelectField label="Type" {...register("type")}>
          <option value="product">Product</option>
          <option value="service">Service</option>
        </SelectField>
        <TextField label="Name" required error={errors.name?.message} {...register("name")} />
        <TextField label="Selling price (BDT)" required inputMode="decimal" placeholder="0.00" error={errors.price?.message} {...register("price")} />
        <SelectField label="Unit" error={errors.unit?.message} {...register("unit")}>
          {UNITS.map((u) => (
            <option key={u} value={u}>
              {u.charAt(0).toUpperCase() + u.slice(1)}
            </option>
          ))}
          <option value="custom">Custom unit…</option>
        </SelectField>
        {unit === "custom" && <TextField label="Custom unit" placeholder="e.g. bag, litre, set" error={errors.custom_unit?.message} {...register("custom_unit")} />}
        <TextField label="SKU / service code (optional)" error={errors.sku?.message} {...register("sku")} />
        <TextField label="Category (optional)" error={errors.category?.message} {...register("category")} />
        <TextField label="Default discount (%)" inputMode="decimal" error={errors.default_discount_percent?.message} {...register("default_discount_percent")} />
        <TextField label="Tax rate (%)" inputMode="decimal" hint="Leave blank if no tax applies. Confirm rates with your tax adviser." error={errors.tax_rate_percent?.message} {...register("tax_rate_percent")} />
        <TextField label="Tax label" placeholder="e.g. VAT" error={errors.tax_label?.message} {...register("tax_label")} />
        <div className="sm:col-span-2">
          <TextAreaField label="Description" error={errors.description?.message} {...register("description")} />
        </div>
        <CheckboxField label="Active (available for new invoices)" {...register("is_active")} />
      </fieldset>
      <div className="flex justify-end">
        <Button type="submit" size="lg" disabled={!access.allowed} loading={formState.isSubmitting}>
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}
