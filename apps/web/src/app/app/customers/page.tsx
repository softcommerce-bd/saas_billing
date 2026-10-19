import { Suspense } from "react";
import { CustomerListView } from "@/features/customers/CustomerListView";
import { PageSpinner } from "@/components/ui/Spinner";

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <CustomerListView />
    </Suspense>
  );
}
