import type { Metadata } from "next";
import { Suspense } from "react";
import { AcceptInvitation } from "@/features/auth/AcceptInvitation";
import { PageSpinner } from "@/components/ui/Spinner";

export const metadata: Metadata = { title: "Accept invitation", robots: { index: false } };

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <AcceptInvitation />
    </Suspense>
  );
}
