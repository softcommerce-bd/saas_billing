import { Suspense } from "react";
import { CustomerDetailView } from "@/features/customers/CustomerDetailView";
import { PageSpinner } from "@/components/ui/Spinner";

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <CustomerDetailView />
    </Suspense>
  );
}
