"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import type { Customer } from "@/lib/api/types";
import { applyApiErrors } from "@/lib/forms";
import { minorToDecimalString } from "@/lib/money";
import { useWriteAccess } from "@/lib/session/SessionProvider";
import { writeBlockedMessage } from "@/components/subscription/WriteGuard";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { CheckboxField, SelectField, TextAreaField, TextField } from "@/components/ui/Field";
import { customerSchema, emptyCustomer, toCustomerPayload, type CustomerFormValues } from "./schema";

export const BD_DISTRICTS = ["Bagerhat", "Bandarban", "Barguna", "Barishal", "Bhola", "Bogura", "Brahmanbaria", "Chandpur", "Chapai Nawabganj", "Chattogram", "Chuadanga", "Cox's Bazar", "Cumilla", "Dhaka", "Dinajpur", "Faridpur", "Feni", "Gaibandha", "Gazipur", "Gopalganj", "Habiganj", "Jamalpur", "Jashore", "Jhalokati", "Jhenaidah", "Joypurhat", "Khagrachhari", "Khulna", "Kishoreganj", "Kurigram", "Kushtia", "Lakshmipur", "Lalmonirhat", "Madaripur", "Magura", "Manikganj", "Meherpur", "Moulvibazar", "Munshiganj", "Mymensingh", "Naogaon", "Narail", "Narayanganj", "Narsingdi", "Natore", "Netrokona", "Nilphamari", "Noakhali", "Pabna", "Panchagarh", "Patuakhali", "Pirojpur", "Rajbari", "Rajshahi", "Rangamati", "Rangpur", "Satkhira", "Shariatpur", "Sherpur", "Sirajganj", "Sunamganj", "Sylhet", "Tangail", "Thakurgaon"];

export function customerToForm(c: Customer): CustomerFormValues {
  return {
    type: c.type,
    name: c.name,
    company_name: c.company_name ?? "",
    mobile: c.mobile_display ?? "",
    secondary_phone: c.secondary_phone ?? "",
    email: c.email ?? "",
    billing_address: c.billing_address ?? "",
    shipping_address: c.shipping_address ?? "",
    district: c.district ?? "",
    bin: c.bin ?? "",
    tin: c.tin ?? "",
    opening_balance: c.opening_balance_minor ? c.opening_balance : "",
    credit_limit: c.credit_limit_minor !== null ? minorToDecimalString(c.credit_limit_minor) : "",
    payment_terms_days: c.payment_terms_days?.toString() ?? "",
    notes: c.notes ?? "",
    status: c.status,
    preferred_language: c.preferred_language,
    sms_opt_in: c.sms_opt_in,
    email_opt_in: c.email_opt_in,
  };
}

export function CustomerForm({ initial, onSubmit, submitLabel }: { initial?: CustomerFormValues; onSubmit: (payload: Record<string, unknown>) => Promise<unknown>; submitLabel: string }) {
  const access = useWriteAccess("customers.manage");
  const [formError, setFormError] = useState<string | null>(null);
  const { register, handleSubmit, setError, control, formState } = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: initial ?? emptyCustomer,
  });
  const type = useWatch({ control, name: "type" });
  const errors = formState.errors;
  const disabled = !access.allowed;

  async function submit(values: CustomerFormValues) {
    setFormError(null);
    try {
      await onSubmit(toCustomerPayload(values));
    } catch (error) {
      setFormError(applyApiErrors(error, setError));
    }
  }

  return (
    <form onSubmit={handleSubmit(submit)} noValidate className="space-y-6">
      {disabled && <Alert tone="warning">{writeBlockedMessage(access.reason)}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}
      <fieldset disabled={disabled} className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="Customer type" {...register("type")}>
            <option value="individual">Individual</option>
            <option value="business">Business</option>
          </SelectField>
          <TextField label="Customer name" required error={errors.name?.message} {...register("name")} />
          {type === "business" && <TextField label="Company name" error={errors.company_name?.message} {...register("company_name")} />}
          <TextField label="Mobile number" type="tel" inputMode="tel" placeholder="01712-345678" error={errors.mobile?.message} {...register("mobile")} />
          <TextField label="Secondary phone" type="tel" error={errors.secondary_phone?.message} {...register("secondary_phone")} />
          <TextField label="Email" type="email" error={errors.email?.message} {...register("email")} />
          <SelectField label="District" error={errors.district?.message} {...register("district")}>
            <option value="">Select district</option>
            {BD_DISTRICTS.map((d) => (
              <option key={d} value={d}>
                {d}
              </option>
            ))}
          </SelectField>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <TextAreaField label="Billing address" error={errors.billing_address?.message} {...register("billing_address")} />
          <TextAreaField label="Shipping address" error={errors.shipping_address?.message} {...register("shipping_address")} />
        </div>

        <details className="rounded-lg border border-border bg-canvas/50 p-4" open={!!initial}>
          <summary className="cursor-pointer text-sm font-semibold">Business, balance and communication</summary>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <TextField label="BIN (optional)" error={errors.bin?.message} {...register("bin")} />
            <TextField label="TIN (optional)" error={errors.tin?.message} {...register("tin")} />
            <TextField label="Opening receivable (BDT)" inputMode="decimal" placeholder="0.00" hint="Amount the customer already owed before using this system." error={errors.opening_balance?.message} {...register("opening_balance")} />
            <TextField label="Credit limit (BDT)" inputMode="decimal" error={errors.credit_limit?.message} {...register("credit_limit")} />
            <TextField label="Payment terms (days)" inputMode="numeric" error={errors.payment_terms_days?.message} {...register("payment_terms_days")} />
            <SelectField label="Preferred language" {...register("preferred_language")}>
              <option value="bn">বাংলা (Bangla)</option>
              <option value="en">English</option>
            </SelectField>
            <SelectField label="Status" {...register("status")}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </SelectField>
            <div className="space-y-2 pt-7">
              <CheckboxField label="Send SMS notifications" {...register("sms_opt_in")} />
              <CheckboxField label="Send email notifications" {...register("email_opt_in")} />
            </div>
          </div>
          <div className="mt-4">
            <TextAreaField label="Notes" error={errors.notes?.message} {...register("notes")} />
          </div>
        </details>
      </fieldset>

      <div className="flex justify-end">
        <Button type="submit" size="lg" disabled={disabled} loading={formState.isSubmitting}>
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}
