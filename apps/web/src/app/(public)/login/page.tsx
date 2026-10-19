import type { Metadata } from "next";
import { Suspense } from "react";
import { LoginForm } from "@/features/auth/LoginForm";
import { PageSpinner } from "@/components/ui/Spinner";

export const metadata: Metadata = { title: "Sign in", robots: { index: false } };

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <LoginForm />
    </Suspense>
  );
}
