"use client";

import Link from "next/link";
import type { EntitlementState } from "@/lib/api/types";
import { formatLocalDate } from "@/lib/dates";

/**
 * Prominent, professional notice shown on every business page while the company is in
 * read-only (expired / unpaid) or suspended mode.
 */
export function ReadOnlyBanner({ state, canManageSubscription }: { state: EntitlementState; canManageSubscription: boolean }) {
  if (state.mode === "full") return null;

  if (state.mode === "suspended") {
    return (
      <section role="alert" aria-labelledby="suspended-title" className="border-b border-danger-700/20 bg-danger-50 px-4 py-4 sm:px-6">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
          <div>
            <h2 id="suspended-title" className="text-base font-semibold text-danger-700">
              Your Company Account Is Suspended
            </h2>
            <p className="mt-1 text-sm text-danger-700">
              Your business information is safe and available in read-only mode. Please contact support to restore access.
            </p>
          </div>
          <ReadOnlyIndicator />
        </div>
      </section>
    );
  }

  const neverPaid = state.reason === "pending_payment";

  return (
    <section role="alert" aria-labelledby="expired-title" className="border-b border-warning-700/30 bg-warning-50 px-4 py-4 sm:px-6">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h2 id="expired-title" className="text-base font-semibold text-warning-700">
              {neverPaid ? "Activate Your Subscription" : "Your Subscription Has Expired"}
            </h2>
            <ReadOnlyIndicator />
          </div>
          <p className="mt-1 text-sm text-warning-700">
            Your business information is safe and available in read-only mode. Renew your subscription to continue creating
            invoices, recording payments and managing collections.
          </p>
          {state.valid_through && !neverPaid && (
            <p className="mt-1 text-sm font-medium text-warning-700">Expired after {formatLocalDate(state.valid_through)}.</p>
          )}
        </div>
        {canManageSubscription ? (
          <div className="flex flex-wrap gap-2">
            <Link href="/app/subscription#packages" className="inline-flex h-10 items-center rounded-lg border border-warning-700/40 bg-white px-4 text-sm font-medium text-warning-700 hover:bg-warning-50">
              View Packages
            </Link>
            <Link href="/app/subscription#packages" className="inline-flex h-10 items-center rounded-lg bg-navy-900 px-4 text-sm font-medium text-white hover:bg-navy-800">
              Renew Subscription
            </Link>
          </div>
        ) : (
          <p className="text-sm text-warning-700">Ask your business owner to renew the subscription.</p>
        )}
      </div>
    </section>
  );
}

export function ReadOnlyIndicator() {
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-warning-700 ring-1 ring-warning-700/30">
      <span aria-hidden="true">●</span> Read-only
    </span>
  );
}
