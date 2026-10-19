import Link from "next/link";
import { productName } from "@/lib/config";
import { AppProviders } from "@/lib/session/SessionProvider";

export default function PublicLayout({ children }: { children: React.ReactNode }) {
  return (
    <AppProviders>
      <div className="flex min-h-screen flex-col bg-white">
        <header className="border-b border-border">
          <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
            <Link href="/" className="text-lg font-semibold text-navy-900">
              {productName}
            </Link>
            <nav aria-label="Public" className="flex items-center gap-1 text-sm sm:gap-4">
              <Link href="/features" className="hidden rounded-md px-2 py-2 text-muted hover:text-ink sm:block">
                Features
              </Link>
              <Link href="/pricing" className="rounded-md px-2 py-2 text-muted hover:text-ink">
                Pricing
              </Link>
              <Link href="/login" className="rounded-md px-2 py-2 font-medium text-ink">
                Sign in
              </Link>
              <Link href="/register" className="inline-flex h-9 items-center rounded-lg bg-brand-600 px-3 font-medium text-white hover:bg-brand-700">
                Get started
              </Link>
            </nav>
          </div>
        </header>
        <div className="flex-1 bg-canvas">{children}</div>
        <footer className="border-t border-border bg-white">
          <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted sm:px-6">
            <p>© {productName}</p>
            <p>Billing and collections for Bangladeshi businesses</p>
          </div>
        </footer>
      </div>
    </AppProviders>
  );
}
