"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { productName } from "@/lib/config";
import { useSession } from "@/lib/session/SessionProvider";
import { PageSpinner } from "@/components/ui/Spinner";
import { cn } from "@/components/ui/cn";

const links = [
  { href: "/admin", label: "Tenants" },
  { href: "/admin/payments", label: "Subscription payments" },
  { href: "/admin/plans", label: "Plans & prices" },
];

/**
 * Platform administration. Shows subscription metadata only; tenant business data is
 * never available here (the API does not expose it to platform roles).
 */
export function AdminShell({ children }: { children: ReactNode }) {
  const { me, isLoading, membership, signOut } = useSession();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!isLoading && !me) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
  }, [isLoading, me, pathname, router]);

  if (isLoading || !me) return <PageSpinner />;
  if (me.platform_role !== "super_admin") {
    return (
      <div className="mx-auto max-w-md px-4 py-24 text-center">
        <h1 className="text-xl font-semibold">Not authorised</h1>
        <p className="mt-2 text-sm text-muted">Platform administration requires the super admin role.</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen">
      <header className="bg-navy-950 text-white">
        <div className="mx-auto flex h-14 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
          <span className="font-semibold">{productName} · Platform admin</span>
          <div className="flex items-center gap-3 text-sm">
            {membership && <Link href="/app" className="text-white/80 hover:text-white">Business app</Link>}
            <button type="button" onClick={() => void signOut()} className="text-white/80 hover:text-white">Sign out</button>
          </div>
        </div>
        <nav aria-label="Admin" className="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 sm:px-6">
          {links.map((l) => (
            <Link key={l.href} href={l.href} aria-current={pathname === l.href ? "page" : undefined} className={cn("whitespace-nowrap border-b-2 px-3 py-2 text-sm", pathname === l.href ? "border-brand-500 text-white" : "border-transparent text-white/70 hover:text-white")}>
              {l.label}
            </Link>
          ))}
        </nav>
      </header>
      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6">{children}</main>
    </div>
  );
}
