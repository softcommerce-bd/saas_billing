import type { Metadata } from "next";
import { Suspense } from "react";
import { AppProviders } from "@/lib/session/SessionProvider";
import { AdminShell } from "@/features/admin/AdminShell";
import { PageSpinner } from "@/components/ui/Spinner";

export const metadata: Metadata = { title: "Platform admin", robots: { index: false, follow: false } };

export default function AdminLayout({ children }: LayoutProps<"/admin">) {
  return (
    <AppProviders>
      <Suspense fallback={<PageSpinner />}>
        <AdminShell>{children}</AdminShell>
      </Suspense>
    </AppProviders>
  );
}
