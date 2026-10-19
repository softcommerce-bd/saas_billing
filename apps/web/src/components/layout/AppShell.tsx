"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import type { Permission } from "@/lib/api/types";
import { productName } from "@/lib/config";
import { useSession } from "@/lib/session/SessionProvider";
import { ReadOnlyBanner } from "@/components/subscription/ReadOnlyBanner";
import { SubscriptionBadge } from "@/components/subscription/SubscriptionBadge";
import { PageSpinner } from "@/components/ui/Spinner";
import { cn } from "@/components/ui/cn";
import { WriteGuard } from "@/components/subscription/WriteGuard";

type NavItem = { href: string; label: string; icon: ReactNode; permission?: Permission };

const icon = (d: string) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="size-5 shrink-0" aria-hidden="true">
    <path d={d} />
  </svg>
);

// Only modules that are implemented appear here; nothing links to unbuilt screens.
const NAV: NavItem[] = [
  { href: "/app", label: "Dashboard", icon: icon("M3 12l9-8 9 8M5 10v10h14V10") },
  { href: "/app/customers", label: "Customers", icon: icon("M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"), permission: "customers.view" },
  { href: "/app/catalog", label: "Products & Services", icon: icon("M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"), permission: "catalog.view" },
  { href: "/app/team", label: "Team", icon: icon("M17 20h5v-2a3 3 0 0 0-5.36-1.86M17 20H7m10 0v-2c0-.66-.13-1.28-.36-1.86M7 20H2v-2a3 3 0 0 1 5.36-1.86M7 20v-2c0-.66.13-1.28.36-1.86m0 0a5 5 0 0 1 9.28 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0"), permission: "users.manage" },
  { href: "/app/subscription", label: "Subscription", icon: icon("M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3z"), permission: "subscription.view" },
  { href: "/app/settings", label: "Company Settings", icon: icon("M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-2.82-1.17l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 3 15.4a1.65 1.65 0 0 0-1.51-1H1.4a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 3 9.4a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 8.6 3a1.65 1.65 0 0 0 1-1.51V1.4a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 21 8.6a1.65 1.65 0 0 0 1.51 1h.09a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z") },
];

export function AppShell({ children }: { children: ReactNode }) {
  const { me, isLoading, membership } = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    if (isLoading) return;
    if (!me) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    else if (!membership && me.platform_role) router.replace("/admin");
  }, [isLoading, me, membership, pathname, router]);

  // Close the mobile drawer after navigation (state adjustment during render, no effect needed).
  const [navPath, setNavPath] = useState(pathname);
  if (navPath !== pathname) {
    setNavPath(pathname);
    setMobileOpen(false);
  }

  if (isLoading || !me) return <PageSpinner />;

  if (!me.email_verified) return <VerifyEmailNotice email={me.email} />;

  if (!membership) {
    return (
      <div className="mx-auto max-w-md px-4 py-24 text-center">
        <h1 className="text-xl font-semibold">No company access</h1>
        <p className="mt-2 text-sm text-muted">Your account is not a member of any active company. Ask your business owner to invite you.</p>
      </div>
    );
  }

  const items = NAV.filter((item) => !item.permission || membership.permissions.includes(item.permission));
  const isActive = (href: string) => (href === "/app" ? pathname === "/app" : pathname.startsWith(href));

  const nav = (
    <nav aria-label="Main" className="flex flex-1 flex-col gap-1 px-3 py-4">
      {items.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          aria-current={isActive(item.href) ? "page" : undefined}
          title={collapsed ? item.label : undefined}
          className={cn(
            "flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors",
            isActive(item.href) ? "bg-white/10 text-white" : "text-white/70 hover:bg-white/5 hover:text-white",
          )}
        >
          {item.icon}
          <span className={cn(collapsed && "lg:sr-only")}>{item.label}</span>
        </Link>
      ))}
    </nav>
  );

  return (
    <div className="flex min-h-screen">
      {/* Desktop sidebar */}
      <aside className={cn("hidden shrink-0 flex-col bg-navy-900 lg:flex", collapsed ? "w-20" : "w-64")}>
        <div className="flex h-16 items-center justify-between px-5">
          <Link href="/app" className={cn("text-lg font-semibold text-white", collapsed && "sr-only")}>
            {productName}
          </Link>
          <button
            type="button"
            onClick={() => setCollapsed((c) => !c)}
            className="rounded-md p-2 text-white/70 hover:bg-white/10 hover:text-white"
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            aria-expanded={!collapsed}
          >
            {icon(collapsed ? "M9 6l6 6-6 6" : "M15 6l-6 6 6 6")}
          </button>
        </div>
        {nav}
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <button type="button" className="absolute inset-0 bg-navy-950/50" aria-label="Close navigation" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 flex w-72 flex-col bg-navy-900">
            <div className="flex h-16 items-center px-5 text-lg font-semibold text-white">{productName}</div>
            {nav}
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onOpenMenu={() => setMobileOpen(true)} />
        <ReadOnlyBanner state={membership.subscription} canManageSubscription={membership.permissions.includes("subscription.manage")} />
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:py-8">{children}</main>
      </div>
    </div>
  );
}

function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { me, membership, selectTenant, signOut } = useSession();
  const router = useRouter();
  const [menuOpen, setMenuOpen] = useState(false);
  const canSearchCustomers = membership?.permissions.includes("customers.view");

  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const term = new FormData(event.currentTarget).get("q")?.toString().trim() ?? "";
    router.push(`/app/customers${term ? `?search=${encodeURIComponent(term)}` : ""}`);
  }

  if (!me || !membership) return null;

  return (
    <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-white/95 px-4 backdrop-blur sm:px-6">
      <button type="button" onClick={onOpenMenu} className="rounded-md p-2 text-ink hover:bg-canvas lg:hidden" aria-label="Open navigation">
        {icon("M4 6h16M4 12h16M4 18h16")}
      </button>

      {me.memberships.length > 1 ? (
        <label className="flex items-center gap-2">
          <span className="sr-only">Company</span>
          <select value={membership.tenant.id} onChange={(e) => selectTenant(e.target.value)} className="h-9 max-w-48 truncate rounded-lg border border-border bg-white px-2 text-sm font-medium">
            {me.memberships.map((m) => (
              <option key={m.tenant.id} value={m.tenant.id}>
                {m.tenant.name}
              </option>
            ))}
          </select>
        </label>
      ) : (
        <span className="hidden max-w-56 truncate text-sm font-semibold text-ink sm:block">{membership.tenant.name}</span>
      )}
      <SubscriptionBadge state={membership.subscription} />

      {canSearchCustomers && (
        <form role="search" onSubmit={search} className="ml-auto hidden w-full max-w-xs md:block">
          <label htmlFor="global-search" className="sr-only">
            Search customers
          </label>
          <input id="global-search" name="q" type="search" placeholder="Search customers by name or mobile" className="h-9 w-full rounded-lg border border-border bg-canvas px-3 text-sm focus:border-brand-500 focus:bg-white focus:outline-none" />
        </form>
      )}

      <div className={cn("flex items-center gap-2", !canSearchCustomers && "ml-auto")}>
        <WriteGuard permission="customers.manage">
          <button type="button" onClick={() => router.push("/app/customers/new")} className="hidden h-9 items-center rounded-lg bg-brand-600 px-3 text-sm font-medium text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-brand-600/50 sm:inline-flex">
            + New customer
          </button>
        </WriteGuard>

        <div className="relative">
          <button type="button" onClick={() => setMenuOpen((o) => !o)} aria-expanded={menuOpen} aria-haspopup="menu" className="flex size-9 items-center justify-center rounded-full bg-navy-900 text-sm font-semibold text-white">
            <span aria-hidden="true">{me.name.slice(0, 1).toUpperCase()}</span>
            <span className="sr-only">Account menu</span>
          </button>
          {menuOpen && (
            <div role="menu" className="absolute right-0 mt-2 w-64 rounded-lg border border-border bg-white p-2 shadow-lg">
              <div className="border-b border-border px-3 pb-2">
                <p className="truncate text-sm font-semibold">{me.name}</p>
                <p className="truncate text-xs text-muted">{me.email}</p>
              </div>
              {me.platform_role && (
                <Link role="menuitem" href="/admin" className="mt-1 block rounded-md px-3 py-2 text-sm hover:bg-canvas">
                  Platform admin
                </Link>
              )}
              <button role="menuitem" type="button" onClick={() => void signOut()} className="mt-1 block w-full rounded-md px-3 py-2 text-left text-sm hover:bg-canvas">
                Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}

function VerifyEmailNotice({ email }: { email: string }) {
  const [sent, setSent] = useState(false);
  const { signOut } = useSession();

  async function resend() {
    const { api } = await import("@/lib/api/client");
    await api.post("auth/email/resend");
    setSent(true);
  }

  return (
    <div className="mx-auto max-w-md px-4 py-24 text-center">
      <h1 className="text-xl font-semibold">Verify your email</h1>
      <p className="mt-2 text-sm text-muted">
        We sent a verification link to <strong>{email}</strong>. Open it to start using your account.
      </p>
      <div className="mt-6 flex justify-center gap-3">
        <button type="button" onClick={() => void resend()} disabled={sent} className="h-10 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white disabled:opacity-60">
          {sent ? "Link sent" : "Resend link"}
        </button>
        <button type="button" onClick={() => void signOut()} className="h-10 rounded-lg border border-border px-4 text-sm">
          Sign out
        </button>
      </div>
    </div>
  );
}
