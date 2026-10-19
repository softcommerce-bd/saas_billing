"use client";

import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { formatBDT } from "@/lib/money";
import { formatDate } from "@/lib/dates";
import { WriteGuard } from "@/components/subscription/WriteGuard";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSpinner } from "@/components/ui/Spinner";
import { useArchiveCustomer, useCustomer, useSaveCustomer } from "./api";
import { CustomerForm, customerToForm } from "./CustomerForm";

export function CustomerDetailView() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const customer = useCustomer(id);
  const save = useSaveCustomer(id);
  const archive = useArchiveCustomer();
  const [editing, setEditing] = useState(false);
  const [saved, setSaved] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);

  if (customer.isPending) return <PageSpinner />;
  if (customer.isError) return <ErrorState message={customer.error.message} onRetry={() => void customer.refetch()} />;

  const c = customer.data;

  return (
    <>
      <PageHeader
        title={c.name}
        description={c.company_name ?? (c.type === "business" ? "Business customer" : "Individual customer")}
        actions={
          !editing && (
            <>
              <WriteGuard permission="customers.manage">
                <Button variant="secondary" onClick={() => { setEditing(true); setSaved(false); }}>
                  Edit
                </Button>
              </WriteGuard>
              <WriteGuard permission="customers.manage">
                <Button
                  variant="ghost"
                  loading={archive.isPending}
                  onClick={async () => {
                    if (!window.confirm(`Archive ${c.name}? Archived customers are hidden from lists.`)) return;
                    setArchiveError(null);
                    try {
                      await archive.mutateAsync(c.id);
                      router.replace("/app/customers");
                    } catch (error) {
                      setArchiveError(error instanceof Error ? error.message : "Could not archive customer.");
                    }
                  }}
                >
                  Archive
                </Button>
              </WriteGuard>
            </>
          )
        }
      />
      {saved && <Alert tone="success" className="mb-4">Customer saved.</Alert>}
      {archiveError && <Alert tone="danger" className="mb-4">{archiveError}</Alert>}

      {editing ? (
        <Card>
          <CardHeader title="Edit customer" actions={<Button variant="ghost" onClick={() => setEditing(false)}>Cancel</Button>} />
          <CardBody>
            <CustomerForm
              initial={customerToForm(c)}
              submitLabel="Save changes"
              onSubmit={async (payload) => {
                await save.mutateAsync(payload);
                setEditing(false);
                setSaved(true);
              }}
            />
          </CardBody>
        </Card>
      ) : (
        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardHeader title="Contact & billing" actions={<Badge tone={c.status === "active" ? "success" : "neutral"}>{c.status === "active" ? "Active" : "Inactive"}</Badge>} />
            <CardBody>
              <dl className="grid gap-4 text-sm sm:grid-cols-2">
                <Detail label="Mobile" value={c.mobile_display} />
                <Detail label="Secondary phone" value={c.secondary_phone} />
                <Detail label="Email" value={c.email} />
                <Detail label="District" value={c.district} />
                <Detail label="Billing address" value={c.billing_address} />
                <Detail label="Shipping address" value={c.shipping_address} />
                <Detail label="BIN" value={c.bin} />
                <Detail label="TIN" value={c.tin} />
                <Detail label="Preferred language" value={c.preferred_language === "bn" ? "Bangla" : "English"} />
                <Detail label="Notifications" value={[c.sms_opt_in && "SMS", c.email_opt_in && "Email"].filter(Boolean).join(", ") || "None"} />
                <Detail label="Customer since" value={formatDate(c.created_at)} />
              </dl>
              {c.notes && <p className="mt-4 whitespace-pre-line rounded-lg bg-canvas p-3 text-sm">{c.notes}</p>}
            </CardBody>
          </Card>
          <Card>
            <CardHeader title="Account" />
            <CardBody>
              <dl className="space-y-3 text-sm">
                <Detail label="Opening receivable" value={formatBDT(c.opening_balance_minor)} />
                <Detail label="Credit limit" value={c.credit_limit_minor !== null ? formatBDT(c.credit_limit_minor) : "Not set"} />
                <Detail label="Payment terms" value={c.payment_terms_days !== null ? `${c.payment_terms_days} days` : "Not set"} />
              </dl>
              <p className="mt-4 text-xs text-muted">Invoice, payment and due history will appear here once the invoicing module is released.</p>
            </CardBody>
          </Card>
        </div>
      )}
    </>
  );
}

function Detail({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-muted">{label}</dt>
      <dd className="mt-0.5 font-medium text-ink">{value || "—"}</dd>
    </div>
  );
}
