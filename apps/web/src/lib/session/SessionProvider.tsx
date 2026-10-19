"use client";

import { MutationCache, QueryCache, QueryClient, QueryClientProvider, useQuery, useQueryClient } from "@tanstack/react-query";
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { api, ApiError, onSubscriptionMode, setApiTenant } from "@/lib/api/client";
import type { Me, Membership, Permission } from "@/lib/api/types";

const TENANT_PREFERENCE_KEY = "smartbill.selectedTenant";
const ME_KEY = ["me"] as const;

/** Errors that mean our view of the subscription is stale. */
const ENTITLEMENT_CODES = new Set(["SUBSCRIPTION_EXPIRED", "TENANT_SUSPENDED"]);

let browserQueryClient: QueryClient | undefined;

function makeQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
      },
      mutations: { retry: false },
    },
    queryCache: new QueryCache({
      onError: (error) => {
        if (error instanceof ApiError && ENTITLEMENT_CODES.has(error.code)) client.invalidateQueries({ queryKey: ME_KEY });
      },
    }),
    mutationCache: new MutationCache({
      onError: (error) => {
        if (error instanceof ApiError && ENTITLEMENT_CODES.has(error.code)) client.invalidateQueries({ queryKey: ME_KEY });
      },
    }),
  });
  return client;
}

function getQueryClient(): QueryClient {
  if (typeof window === "undefined") return makeQueryClient();
  browserQueryClient ??= makeQueryClient();
  return browserQueryClient;
}

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={getQueryClient()}>
      <SessionProvider>{children}</SessionProvider>
    </QueryClientProvider>
  );
}

export type SessionValue = {
  me: Me | null;
  isLoading: boolean;
  membership: Membership | null;
  tenantId: string | null;
  selectTenant: (tenantId: string) => void;
  refresh: () => Promise<unknown>;
  signOut: () => Promise<void>;
};

/** Exported for component tests; application code uses useSession(). */
export const SessionContext = createContext<SessionValue | null>(null);

async function fetchMe(): Promise<Me | null> {
  try {
    const response = await api.get<{ data: Me }>("auth/me");
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
}

function readPreference(): string | null {
  try {
    return window.localStorage.getItem(TENANT_PREFERENCE_KEY);
  } catch {
    return null;
  }
}

function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const meQuery = useQuery({ queryKey: ME_KEY, queryFn: fetchMe, staleTime: 60_000 });
  const me = meQuery.data ?? null;
  const [preferredTenant, setPreferredTenant] = useState<string | null>(() => (typeof window === "undefined" ? null : readPreference()));

  const membership = useMemo(() => {
    if (!me || me.memberships.length === 0) return null;
    return me.memberships.find((m) => m.tenant.id === preferredTenant) ?? me.memberships[0];
  }, [me, preferredTenant]);

  const tenantId = membership?.tenant.id ?? null;
  // Set synchronously during render so child queries fired in the same pass carry the header.
  setApiTenant(tenantId);

  // Server reports a different access mode than we show (e.g. expired mid-session): refresh.
  useEffect(() => {
    return onSubscriptionMode((mode) => {
      if (membership && mode !== membership.subscription.mode) {
        void queryClient.invalidateQueries({ queryKey: ME_KEY });
      }
    });
  }, [membership, queryClient]);

  const selectTenant = useCallback(
    (id: string) => {
      try {
        window.localStorage.setItem(TENANT_PREFERENCE_KEY, id);
      } catch {
        // Preference only; ignore storage failures.
      }
      setPreferredTenant(id);
      setApiTenant(id);
      queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== "me" });
    },
    [queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      await api.post("auth/logout");
    } finally {
      setApiTenant(null);
      queryClient.clear();
      // Full reload on purpose: drops every in-memory trace of the previous session.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.assign("/login");
    }
  }, [queryClient]);

  const value = useMemo<SessionValue>(
    () => ({
      me,
      isLoading: meQuery.isPending,
      membership,
      tenantId,
      selectTenant,
      refresh: () => queryClient.invalidateQueries({ queryKey: ME_KEY }),
      signOut,
    }),
    [me, meQuery.isPending, membership, tenantId, selectTenant, queryClient, signOut],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error("useSession must be used within AppProviders");
  return value;
}

export function usePermission(permission: Permission): boolean {
  const { membership } = useSession();
  return membership?.permissions.includes(permission) ?? false;
}

export type WriteAccess = { allowed: boolean; reason: "read_only" | "suspended" | "permission" | null };

/**
 * Whether the current user may perform a business mutation. UI hint only — the API
 * enforces the same rules and is the authority.
 */
export function useWriteAccess(permission: Permission): WriteAccess {
  const { membership } = useSession();
  if (!membership) return { allowed: false, reason: "permission" };
  if (membership.subscription.mode === "suspended") return { allowed: false, reason: "suspended" };
  if (!membership.subscription.can_write) return { allowed: false, reason: "read_only" };
  if (!membership.permissions.includes(permission)) return { allowed: false, reason: "permission" };
  return { allowed: true, reason: null };
}
