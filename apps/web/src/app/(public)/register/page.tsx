import type { Metadata } from "next";
import { Suspense } from "react";
import { RegisterForm } from "@/features/auth/RegisterForm";
import { PageSpinner } from "@/components/ui/Spinner";

export const metadata: Metadata = { title: "Create account", robots: { index: false } };

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <RegisterForm />
    </Suspense>
  );
}
