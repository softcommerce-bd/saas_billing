import type { Metadata } from "next";
import { Suspense } from "react";
import { AppProviders } from "@/lib/session/SessionProvider";
import { AppShell } from "@/components/layout/AppShell";
import { PageSpinner } from "@/components/ui/Spinner";

export const metadata: Metadata = { robots: { index: false, follow: false } };

// The authenticated app is rendered on the client (session cookie + API); the static
// shell is just the loading state.
export default function BusinessLayout({ children }: LayoutProps<"/app">) {
  return (
    <AppProviders>
      <Suspense fallback={<PageSpinner />}>
        <AppShell>{children}</AppShell>
      </Suspense>
    </AppProviders>
  );
}
