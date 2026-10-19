import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import type { ReactElement } from "react";
import type { EntitlementState, Me, Membership, Permission } from "@/lib/api/types";
import { SessionContext, type SessionValue } from "@/lib/session/SessionProvider";

export const activeState: EntitlementState = {
  mode: "full", can_write: true, reason: null, expires_at: "2027-12-31T18:00:00+00:00", valid_through: "2027-12-31",
  is_trial: false, plan_code: "yearly", override_active: false, override_expires_at: null, can_renew: true,
};

export const expiredState: EntitlementState = { ...activeState, mode: "read_only", can_write: false, reason: "expired", valid_through: "2027-01-31" };

export const suspendedState: EntitlementState = { ...activeState, mode: "suspended", can_write: false, reason: "suspended", can_renew: false };

export function makeMembership(permissions: Permission[], subscription: EntitlementState = activeState): Membership {
  return {
    tenant: { id: "01jtenant0000000000000000a", name: "Test Traders", slug: "test-traders", currency: "BDT", timezone: "Asia/Dhaka", onboarding_completed: true },
    roles: ["owner"],
    permissions,
    subscription,
  };
}

export function renderWithSession(ui: ReactElement, membership: Membership | null) {
  const me: Me = { id: "u1", name: "Rahim Uddin", email: "rahim@example.com", email_verified: true, locale: "en", platform_role: null, memberships: membership ? [membership] : [] };
  const value: SessionValue = {
    me, isLoading: false, membership, tenantId: membership?.tenant.id ?? null,
    selectTenant: () => {}, refresh: async () => undefined, signOut: async () => {},
  };
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SessionContext.Provider value={value}>{ui}</SessionContext.Provider>
    </QueryClientProvider>,
  );
}
