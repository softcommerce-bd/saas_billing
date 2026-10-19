"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api/client";
import type { CatalogItem, Customer, Paginated } from "@/lib/api/types";
import { formatDate, formatLocalDate } from "@/lib/dates";
import { usePermission, useSession } from "@/lib/session/SessionProvider";
import { SubscriptionBadge } from "@/components/subscription/SubscriptionBadge";
import { WriteGuard } from "@/components/subscription/WriteGuard";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";

/**
 * Dashboard shows only figures the system can compute today. Financial KPIs (collected
 * today, outstanding, overdue…) arrive with the invoicing and collections modules;
 * no placeholder numbers are displayed.
 */
export function DashboardView() {
  const { me, membership, tenantId } = useSession();
  const router = useRouter();
  const canCustomers = usePermission("customers.view");
  const canCatalog = usePermission("catalog.view");

  const customers = useQuery({
    queryKey: ["customers", tenantId, { dashboard: true }],
    queryFn: () => api.get<Paginated<Customer>>("customers", { per_page: 5, sort: "-created_at" }),
    enabled: !!tenantId && canCustomers,
  });
  const catalog = useQuery({
    queryKey: ["catalog", tenantId, { dashboard: true }],
    queryFn: () => api.get<Paginated<CatalogItem>>("catalog-items", { per_page: 5 }),
    enabled: !!tenantId && canCatalog,
  });

  if (!me || !membership) return null;
  const state = membership.subscription;

  return (
    <>
      <PageHeader
        title={`Welcome, ${me.name.split(" ")[0]}`}
        description={membership.tenant.name}
        actions={
          <>
            <WriteGuard permission="customers.manage">
              <Button onClick={() => router.push("/app/customers/new")}>Add customer</Button>
            </WriteGuard>
            <WriteGuard permission="catalog.manage">
              <Button variant="secondary" onClick={() => router.push("/app/catalog/new")}>Add product/service</Button>
            </WriteGuard>
          </>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {canCustomers && <StatCard label="Customers" value={customers.data?.meta.total} loading={customers.isPending} error={customers.isError} href="/app/customers" />}
        {canCatalog && <StatCard label="Products & services" value={catalog.data?.meta.total} loading={catalog.isPending} error={catalog.isError} href="/app/catalog" />}
        <Card>
          <CardBody>
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted">Subscription</p>
              <SubscriptionBadge state={state} />
            </div>
            <p className="mt-2 text-2xl font-semibold tracking-tight">{state.mode === "full" ? "Active" : state.mode === "suspended" ? "Suspended" : "Read-only"}</p>
            <p className="text-sm text-muted">{state.valid_through ? `${state.mode === "full" ? "Valid through" : "Ended"} ${formatLocalDate(state.valid_through)}` : "Not activated"}</p>
          </CardBody>
        </Card>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        {canCustomers && (
          <Card>
            <CardHeader title="Recently added customers" actions={<Link href="/app/customers" className="text-sm font-medium text-brand-700">View all</Link>} />
            {customers.isPending ? (
              <div className="flex justify-center py-8"><Spinner className="size-5 text-muted" /></div>
            ) : customers.isError ? (
              <ErrorState message="Could not load customers." onRetry={() => void customers.refetch()} />
            ) : customers.data.data.length === 0 ? (
              <CardBody className="text-sm text-muted">No customers yet.</CardBody>
            ) : (
              <ul className="divide-y divide-border">
                {customers.data.data.map((c) => (
                  <li key={c.id}>
                    <Link href={`/app/customers/${c.id}`} className="flex justify-between gap-3 px-5 py-3 text-sm hover:bg-canvas/60">
                      <span className="font-medium">{c.name}</span>
                      <span className="text-muted">{formatDate(c.created_at)}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )}
        <Card>
          <CardHeader title="Coming next" description="These modules are being built and will appear here when released." />
          <CardBody>
            <ul className="space-y-2 text-sm text-muted">
              <li>Invoice builder with A4 PDF, Bangla fonts and secure share links</li>
              <li>Payments, partial payments and allocations across invoices</li>
              <li>Today&apos;s expected collection queue, dues and overdue tracking</li>
              <li>SMS and email reminders, and business reports</li>
            </ul>
          </CardBody>
        </Card>
      </div>
    </>
  );
}

function StatCard({ label, value, loading, error, href }: { label: string; value: number | undefined; loading: boolean; error: boolean; href: string }) {
  return (
    <Link href={href} className="block rounded-card border border-border bg-white p-5 shadow-sm hover:border-brand-500">
      <p className="text-sm text-muted">{label}</p>
      <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{loading ? <Spinner className="size-5 text-muted" /> : error ? "—" : value}</p>
    </Link>
  );
}
