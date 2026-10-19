"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { api, ApiError, newIdempotencyKey } from "@/lib/api/client";
import type { Paginated, Plan, SubscriptionDetails, SubscriptionPayment } from "@/lib/api/types";
import { formatDate, formatDateTime, formatLocalDate } from "@/lib/dates";
import { formatBDT } from "@/lib/money";
import { usePermission, useSession } from "@/lib/session/SessionProvider";
import { SubscriptionBadge } from "@/components/subscription/SubscriptionBadge";
import { Alert } from "@/components/ui/Alert";
import { Badge, type Tone } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSpinner } from "@/components/ui/Spinner";
import { PlanCards } from "./PlanCards";

export const paymentStatusTone: Record<SubscriptionPayment["status"], Tone> = {
  initiated: "info", unknown: "warning", completed: "success", failed: "danger", cancelled: "neutral", expired: "neutral", refunded: "neutral", partially_refunded: "warning",
};

const sourceLabel = { payment: "Payment", trial: "Free trial", complimentary: "Complimentary extension" } as const;

export function SubscriptionView() {
  const { tenantId, membership } = useSession();
  const canManage = usePermission("subscription.manage");
  const details = useQuery({ queryKey: ["subscription", tenantId], queryFn: () => api.get<{ data: SubscriptionDetails }>("subscription").then((r) => r.data), enabled: !!tenantId });
  const payments = useQuery({ queryKey: ["subscription-payments", tenantId], queryFn: () => api.get<Paginated<SubscriptionPayment>>("subscription/payments"), enabled: !!tenantId });

  if (details.isPending) return <PageSpinner />;
  if (details.isError) return <ErrorState message={details.error.message} onRetry={() => void details.refetch()} />;

  const s = details.data;
  const state = s.entitlement;

  return (
    <>
      <PageHeader title="Subscription & Billing" description="Your package with the platform. This is separate from your own customers' invoices." />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Current subscription" actions={<SubscriptionBadge state={state} />} />
          <CardBody>
            <dl className="grid gap-4 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-muted">Package</dt>
                <dd className="mt-0.5 font-medium">{state.is_trial ? "Free trial" : (s.plan?.name ?? "—")}</dd>
              </div>
              <div>
                <dt className="text-muted">{state.mode === "full" ? "Valid through" : "Expired after"}</dt>
                <dd className="mt-0.5 font-medium">{formatLocalDate(state.valid_through)}</dd>
              </div>
              <div>
                <dt className="text-muted">Access</dt>
                <dd className="mt-0.5 font-medium">{state.can_write ? "Full access" : "Read-only"}</dd>
              </div>
            </dl>
            {state.override_active && (
              <Alert tone="info" className="mt-4">Temporary access granted by support until {formatDateTime(state.override_expires_at)}.</Alert>
            )}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="How renewal works" />
          <CardBody className="space-y-2 text-sm text-muted">
            <p>Pay securely on the official bKash checkout page. Your subscription activates only after bKash confirms the payment to us.</p>
            <p>Renewing early adds the new period after your current end date. Renewing after expiry starts from the day of payment.</p>
          </CardBody>
        </Card>
      </div>

      <section id="packages" className="mt-8 scroll-mt-24">
        <h2 className="mb-4 text-lg font-semibold">{state.mode === "full" ? "Renew or extend" : "Choose a package to renew"}</h2>
        {state.mode === "suspended" ? (
          <Alert tone="danger">Renewal is unavailable while the account is suspended. Please contact support.</Alert>
        ) : canManage ? (
          <PlanCards highlightCode={state.plan_code} renderAction={(plan) => <CheckoutButton plan={plan} />} />
        ) : (
          <Alert tone="info">Only the business owner can renew the subscription.</Alert>
        )}
      </section>

      <div className="mt-8 grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Payment history" />
          {payments.isPending ? (
            <PageSpinner />
          ) : payments.isError ? (
            <ErrorState message={payments.error.message} onRetry={() => void payments.refetch()} />
          ) : payments.data.data.length === 0 ? (
            <CardBody className="text-sm text-muted">No subscription payments yet.</CardBody>
          ) : (
            <ul className="divide-y divide-border">
              {payments.data.data.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                  <div>
                    <p className="font-medium">
                      {formatBDT(p.amount_minor)} · {p.plan_code?.replace("_", "-")}
                    </p>
                    <p className="text-muted">
                      {formatDateTime(p.created_at)}
                      {p.transaction_id && <> · Trx {p.transaction_id}</>}
                    </p>
                  </div>
                  <Badge tone={paymentStatusTone[p.status]}>{p.status.replace("_", " ")}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card>
          <CardHeader title="Validity history" />
          {s.periods.length === 0 ? (
            <CardBody className="text-sm text-muted">No periods yet.</CardBody>
          ) : (
            <ul className="divide-y divide-border">
              {s.periods.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                  <div>
                    <p className="font-medium">{sourceLabel[p.source]}</p>
                    <p className="text-muted">
                      {formatDate(p.period_start)} → until {formatDate(p.period_end)}
                    </p>
                  </div>
                  {p.revoked && <Badge tone="danger">Revoked</Badge>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
      {membership && <p className="mt-6 text-xs text-muted">Company: {membership.tenant.name}</p>}
    </>
  );
}

function CheckoutButton({ plan }: { plan: Plan }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function start() {
    setBusy(true);
    setError(null);
    try {
      // One idempotency key per click: retries of the same request never create duplicates.
      const order = await api.post<{ data: { id: string } }>("subscription/orders", { plan_id: plan.id }, newIdempotencyKey());
      const checkout = await api.post<{ data: { redirect_url: string } }>(`subscription/orders/${order.data.id}/checkout`, undefined, newIdempotencyKey());
      window.location.assign(checkout.data.redirect_url);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not start the payment.");
      setBusy(false);
    }
  }

  return (
    <div className="space-y-2">
      <Button className="w-full" loading={busy} onClick={() => void start()}>
        Pay {formatBDT(plan.price_minor)} with bKash
      </Button>
      {error && <p role="alert" className="text-sm text-danger-700">{error}</p>}
    </div>
  );
}
