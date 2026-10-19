"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api, ApiError } from "@/lib/api/client";
import type { AdminTenant, Paginated, Plan, SubscriptionPayment } from "@/lib/api/types";
import { formatDate, formatDateTime, formatLocalDate } from "@/lib/dates";
import { formatBDT, isValidAmount } from "@/lib/money";
import { SubscriptionBadge } from "@/components/subscription/SubscriptionBadge";
import { paymentStatusTone } from "@/features/subscription/SubscriptionView";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Pagination } from "@/components/ui/Pagination";
import { PageSpinner } from "@/components/ui/Spinner";

type AdminPage<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };

function useNotice() {
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const fail = (e: unknown) => setNotice({ tone: "danger", text: e instanceof ApiError ? e.message : "Action failed." });
  return { notice, setNotice, fail };
}

export function AdminTenantsView() {
  const queryClient = useQueryClient();
  const [state, setState] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const { notice, setNotice, fail } = useNotice();
  const tenants = useQuery({ queryKey: ["admin-tenants", state, search, page], queryFn: () => api.get<AdminPage<AdminTenant>>("admin/tenants", { state, search, page }) });

  const act = useMutation({
    mutationFn: ({ path, body }: { path: string; body: Record<string, unknown> }) => api.post(path, body),
    onSuccess: () => { setNotice({ tone: "success", text: "Done. The action was recorded in the audit log." }); void queryClient.invalidateQueries({ queryKey: ["admin-tenants"] }); },
    onError: fail,
  });

  function prompt(label: string): string | null {
    const reason = window.prompt(`${label}\n\nReason (required, recorded in the audit log):`);
    return reason && reason.trim().length >= 5 ? reason.trim() : null;
  }

  return (
    <>
      <PageHeader title="Tenants" description="Subscription state of every company. Business data is not accessible here." />
      {notice && <Alert tone={notice.tone} className="mb-4">{notice.text}</Alert>}
      <Card>
        <div className="flex flex-wrap gap-3 border-b border-border px-5 py-4">
          <input type="search" aria-label="Search tenants" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Search by name" className="h-10 min-w-0 flex-1 rounded-lg border border-border px-3 text-sm" />
          <select aria-label="State" value={state} onChange={(e) => { setState(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-border bg-white px-3 text-sm">
            <option value="">All</option>
            <option value="active">Active (paid)</option>
            <option value="trial">Trial</option>
            <option value="expired">Expired</option>
            <option value="pending_payment">Never paid</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
        {tenants.isPending ? <PageSpinner /> : tenants.isError ? <ErrorState message={tenants.error.message} onRetry={() => void tenants.refetch()} /> : tenants.data.data.length === 0 ? <EmptyState title="No tenants" /> : (
          <>
            <ul className="divide-y divide-border">
              {tenants.data.data.map((t) => (
                <li key={t.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm">
                  <div>
                    <p className="font-medium">{t.name}</p>
                    <p className="text-muted">
                      Joined {formatDate(t.created_at)} · {t.subscription.plan_code ?? "no plan"} · valid through {formatLocalDate(t.entitlement.valid_through)}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <SubscriptionBadge state={t.entitlement} />
                    <Button size="sm" variant="secondary" onClick={() => { const r = prompt(`Grant 7 complimentary days to ${t.name}?`); if (r) act.mutate({ path: `admin/tenants/${t.id}/extensions`, body: { days: 7, reason: r } }); }}>
                      +7 days
                    </Button>
                    {t.suspended_at ? (
                      <Button size="sm" variant="secondary" onClick={() => { const r = prompt(`Unsuspend ${t.name}?`); if (r) act.mutate({ path: `admin/tenants/${t.id}/unsuspend`, body: { reason: r } }); }}>Unsuspend</Button>
                    ) : (
                      <Button size="sm" variant="danger" onClick={() => { const r = prompt(`Suspend ${t.name}? The company becomes read-only.`); if (r) act.mutate({ path: `admin/tenants/${t.id}/suspend`, body: { reason: r } }); }}>Suspend</Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
            <Pagination page={tenants.data.meta.current_page} lastPage={tenants.data.meta.last_page} total={tenants.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}

export function AdminPaymentsView() {
  const queryClient = useQueryClient();
  const [review, setReview] = useState(false);
  const [page, setPage] = useState(1);
  const { notice, setNotice, fail } = useNotice();
  const payments = useQuery({
    queryKey: ["admin-payments", review, page],
    queryFn: () => api.get<Paginated<SubscriptionPayment>>("admin/subscription-payments", { requires_review: review ? 1 : undefined, page }),
  });
  const reconcile = useMutation({
    mutationFn: (id: string) => api.post(`admin/subscription-payments/${id}/reconcile`),
    onSuccess: () => { setNotice({ tone: "success", text: "Payment re-checked with the gateway." }); void queryClient.invalidateQueries({ queryKey: ["admin-payments"] }); },
    onError: fail,
  });

  return (
    <>
      <PageHeader
        title="Subscription payments"
        description="Gateway-verified payments only. Payments can never be marked as verified manually."
        actions={<label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={review} onChange={(e) => { setReview(e.target.checked); setPage(1); }} className="size-4" /> Needs review only</label>}
      />
      {notice && <Alert tone={notice.tone} className="mb-4">{notice.text}</Alert>}
      <Card>
        {payments.isPending ? <PageSpinner /> : payments.isError ? <ErrorState message={payments.error.message} onRetry={() => void payments.refetch()} /> : payments.data.data.length === 0 ? <EmptyState title="No payments" /> : (
          <>
            <ul className="divide-y divide-border">
              {payments.data.data.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm">
                  <div>
                    <p className="font-medium">{formatBDT(p.amount_minor)} · {p.plan_code} · {p.merchant_reference}</p>
                    <p className="text-muted">
                      {formatDateTime(p.created_at)} · {p.gateway} ({p.environment}){p.transaction_id && <> · Trx {p.transaction_id}</>}
                      {p.failure_code && <> · {p.failure_code}</>}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    {p.requires_review && <Badge tone="warning">Review</Badge>}
                    <Badge tone={paymentStatusTone[p.status]}>{p.status.replace("_", " ")}</Badge>
                    {(p.status === "initiated" || p.status === "unknown") && (
                      <Button size="sm" variant="secondary" loading={reconcile.isPending && reconcile.variables === p.id} onClick={() => reconcile.mutate(p.id)}>Re-check</Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
            <Pagination page={payments.data.meta.current_page} lastPage={payments.data.meta.last_page} total={payments.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>
      <p className="mt-4 text-xs text-muted">Refunds and disputes are available through the admin API and are always confirmed with the gateway before any change is recorded.</p>
    </>
  );
}

export function AdminPlansView() {
  const queryClient = useQueryClient();
  const { notice, setNotice, fail } = useNotice();
  const plans = useQuery({ queryKey: ["admin-plans"], queryFn: () => api.get<{ data: Plan[] }>("admin/plans").then((r) => r.data) });
  const [prices, setPrices] = useState<Record<string, string>>({});
  const save = useMutation({
    mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) => api.patch(`admin/plans/${id}`, body),
    onSuccess: () => { setNotice({ tone: "success", text: "Plan updated. Existing orders keep their original price." }); void queryClient.invalidateQueries({ queryKey: ["admin-plans"] }); void queryClient.invalidateQueries({ queryKey: ["plans"] }); },
    onError: fail,
  });

  return (
    <>
      <PageHeader title="Plans & prices" description="Prices in BDT. Changes apply to new orders only and are audited." />
      {notice && <Alert tone={notice.tone} className="mb-4">{notice.text}</Alert>}
      <Card>
        {plans.isPending ? <PageSpinner /> : plans.isError ? <ErrorState message={plans.error.message} /> : (
          <ul className="divide-y divide-border">
            {plans.data.map((plan) => {
              const value = prices[plan.id] ?? plan.price;
              const valid = isValidAmount(value) && Number(value) > 0;
              return (
                <li key={plan.id} className="flex flex-wrap items-end justify-between gap-3 px-5 py-4">
                  <div>
                    <p className="font-medium">{plan.name} <span className="text-sm text-muted">({plan.duration_months} months)</span></p>
                    <p className="text-sm text-muted">Current: {formatBDT(plan.price_minor)} · {plan.is_active ? "Available" : "Hidden"}</p>
                  </div>
                  <div className="flex flex-wrap items-end gap-2">
                    <label className="text-sm">
                      <span className="mb-1 block text-muted">New price (BDT)</span>
                      <input value={value} onChange={(e) => setPrices((p) => ({ ...p, [plan.id]: e.target.value }))} inputMode="decimal" aria-invalid={!valid} className="h-10 w-36 rounded-lg border border-border px-3 aria-[invalid=true]:border-danger-600" />
                    </label>
                    <Button disabled={!valid || value === plan.price} onClick={() => save.mutate({ id: plan.id, body: { price: value } })}>Save price</Button>
                    <Button variant="secondary" onClick={() => save.mutate({ id: plan.id, body: { is_active: !plan.is_active } })}>{plan.is_active ? "Hide" : "Show"}</Button>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </Card>
    </>
  );
}
