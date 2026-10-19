"use client";

import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api/client";
import type { Plan } from "@/lib/api/types";
import { formatBDT } from "@/lib/money";
import { ErrorState } from "@/components/ui/EmptyState";
import { PageSpinner } from "@/components/ui/Spinner";
import { cn } from "@/components/ui/cn";

export function usePlans() {
  return useQuery({ queryKey: ["plans"], queryFn: () => api.get<{ data: Plan[] }>("plans").then((r) => r.data), staleTime: 5 * 60_000 });
}

export function savingsPercent(plan: Plan, monthly: Plan | undefined): number {
  if (!monthly || plan.duration_months === 1 || monthly.price_minor === 0) return 0;
  const full = monthly.price_minor * plan.duration_months;
  return Math.max(0, Math.round(((full - plan.price_minor) / full) * 100));
}

/**
 * Package cards with prices from the API (admin-configured, never hard-coded).
 * `renderAction` lets the pricing page link to sign-up and the subscription page start checkout.
 */
export function PlanCards({ renderAction, highlightCode }: { renderAction: (plan: Plan) => React.ReactNode; highlightCode?: string | null }) {
  const plans = usePlans();

  if (plans.isPending) return <PageSpinner label="Loading packages…" />;
  if (plans.isError) return <ErrorState message="Could not load packages." onRetry={() => void plans.refetch()} />;

  const monthly = plans.data.find((p) => p.code === "monthly");

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {plans.data.map((plan) => {
        const savings = savingsPercent(plan, monthly);
        return (
          <div key={plan.id} className={cn("flex flex-col rounded-card border bg-white p-5", plan.code === highlightCode ? "border-brand-500 ring-2 ring-brand-100" : "border-border")}>
            <div className="flex items-center justify-between gap-2">
              <h3 className="font-semibold text-ink">{plan.name}</h3>
              {savings > 0 && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">Save {savings}%</span>}
            </div>
            <p className="mt-3 text-2xl font-semibold tracking-tight text-ink">{formatBDT(plan.price_minor)}</p>
            <p className="text-sm text-muted">
              for {plan.duration_months} {plan.duration_months === 1 ? "month" : "months"}
              {plan.duration_months > 1 && <> · {formatBDT(plan.monthly_equivalent_minor)}/month</>}
            </p>
            {plan.entitlements && (
              <ul className="mt-4 space-y-1 text-sm text-muted">
                <li>Up to {plan.entitlements.max_users ?? "unlimited"} team members</li>
                <li>{plan.entitlements.sms_monthly_allowance} SMS per month</li>
                <li>{Math.round(plan.entitlements.storage_mb / 1024)} GB document storage</li>
              </ul>
            )}
            <div className="mt-auto pt-5">{renderAction(plan)}</div>
          </div>
        );
      })}
    </div>
  );
}
