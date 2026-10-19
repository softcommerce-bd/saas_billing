import { Suspense } from "react";
import { PaymentResultView } from "@/features/subscription/PaymentResultView";
import { PageSpinner } from "@/components/ui/Spinner";

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <PaymentResultView />
    </Suspense>
  );
}
