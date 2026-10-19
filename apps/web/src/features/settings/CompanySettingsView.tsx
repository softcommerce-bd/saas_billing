"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { api } from "@/lib/api/client";
import type { Company } from "@/lib/api/types";
import { applyApiErrors } from "@/lib/forms";
import { useSession, useWriteAccess } from "@/lib/session/SessionProvider";
import { writeBlockedMessage } from "@/components/subscription/WriteGuard";
import { BD_DISTRICTS } from "@/features/customers/CustomerForm";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/EmptyState";
import { SelectField, TextAreaField, TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSpinner } from "@/components/ui/Spinner";

const schema = z.object({
  name: z.string().trim().min(2, "Enter the company name.").max(160),
  legal_name: z.string().trim().max(200),
  email: z.string().trim().email("Enter a valid email.").or(z.literal("")),
  phone: z.string().trim().max(20),
  address: z.string().trim().max(1000),
  district: z.string(),
  bin: z.string().trim().max(32),
  tin: z.string().trim().max(32),
  locale: z.enum(["bn", "en"]),
  invoice_prefix: z.string().trim().min(1, "Enter a prefix.").max(12).regex(/^[A-Za-z0-9\-/]+$/, "Use letters, numbers, - or / only."),
});
type Values = z.infer<typeof schema>;

export function CompanySettingsView() {
  const { tenantId, refresh } = useSession();
  const company = useQuery({ queryKey: ["company", tenantId], queryFn: () => api.get<{ data: Company }>("company").then((r) => r.data), enabled: !!tenantId });

  if (company.isPending) return <PageSpinner />;
  if (company.isError) return <ErrorState message={company.error.message} onRetry={() => void company.refetch()} />;

  return (
    <>
      <PageHeader title="Company settings" description="Details shown on your invoices and receipts." />
      <Card>
        <CardBody>
          <SettingsForm company={company.data} onSaved={refresh} />
        </CardBody>
      </Card>
    </>
  );
}

function SettingsForm({ company, onSaved }: { company: Company; onSaved: () => Promise<unknown> }) {
  const queryClient = useQueryClient();
  const access = useWriteAccess("settings.manage");
  const [status, setStatus] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const { register, handleSubmit, setError, formState } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: company.name, legal_name: company.legal_name ?? "", email: company.email ?? "", phone: company.phone ?? "",
      address: company.address ?? "", district: company.district ?? "", bin: company.bin ?? "", tin: company.tin ?? "",
      locale: company.locale, invoice_prefix: company.invoice_prefix,
    },
  });
  const e = formState.errors;

  async function submit(values: Values) {
    setStatus(null);
    const payload = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === "" ? null : v]));
    try {
      const { data } = await api.patch<{ data: Company }>("company", payload);
      queryClient.setQueryData(["company", company.id], data);
      await onSaved();
      setStatus({ tone: "success", text: "Company settings saved." });
    } catch (error) {
      const message = applyApiErrors(error, setError);
      if (message) setStatus({ tone: "danger", text: message });
    }
  }

  return (
    <form onSubmit={handleSubmit(submit)} noValidate className="space-y-6">
      {!access.allowed && <Alert tone="warning">{writeBlockedMessage(access.reason)}</Alert>}
      {status && <Alert tone={status.tone}>{status.text}</Alert>}
      <fieldset disabled={!access.allowed} className="grid gap-4 sm:grid-cols-2">
        <TextField label="Company name" required error={e.name?.message} {...register("name")} />
        <TextField label="Legal name" error={e.legal_name?.message} {...register("legal_name")} />
        <TextField label="Email" type="email" error={e.email?.message} {...register("email")} />
        <TextField label="Phone" type="tel" error={e.phone?.message} {...register("phone")} />
        <SelectField label="District" {...register("district")}>
          <option value="">Select district</option>
          {BD_DISTRICTS.map((d) => <option key={d} value={d}>{d}</option>)}
        </SelectField>
        <SelectField label="Default document language" {...register("locale")}>
          <option value="bn">বাংলা (Bangla)</option>
          <option value="en">English</option>
        </SelectField>
        <TextField label="BIN (optional)" error={e.bin?.message} {...register("bin")} />
        <TextField label="TIN (optional)" error={e.tin?.message} {...register("tin")} />
        <TextField label="Invoice number prefix" hint="Example: INV- gives INV-000123." error={e.invoice_prefix?.message} {...register("invoice_prefix")} />
        <TextField label="Currency" value={`${company.currency} (Bangladeshi Taka)`} disabled readOnly />
        <div className="sm:col-span-2">
          <TextAreaField label="Address" error={e.address?.message} {...register("address")} />
        </div>
      </fieldset>
      <div className="flex justify-end">
        <Button type="submit" size="lg" disabled={!access.allowed} loading={formState.isSubmitting}>Save settings</Button>
      </div>
    </form>
  );
}
