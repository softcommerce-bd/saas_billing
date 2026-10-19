import { Suspense } from "react";
import { CatalogEditView } from "@/features/catalog/CatalogViews";
import { PageSpinner } from "@/components/ui/Spinner";

export default function Page() {
  return (
    <Suspense fallback={<PageSpinner />}>
      <CatalogEditView />
    </Suspense>
  );
}
