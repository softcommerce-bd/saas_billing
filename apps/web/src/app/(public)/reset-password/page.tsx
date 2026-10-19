import type { Metadata } from "next";
import { Suspense } from "react";
import { ResetPasswordForm } from "@/features/auth/PasswordForms";
import { PageSpinner } from "@/components/ui/Spinner";

export const metadata: Metadata = { title: "Reset password", robots: { index: false } };

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <ResetPasswordForm />
    </Suspense>
  );
}
