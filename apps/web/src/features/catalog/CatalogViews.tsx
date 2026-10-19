"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { formatBDT } from "@/lib/money";
import { WriteGuard } from "@/components/subscription/WriteGuard";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody } from "@/components/ui/Card";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Pagination } from "@/components/ui/Pagination";
import { PageSpinner } from "@/components/ui/Spinner";
import { useArchiveCatalogItem, useCatalogItem, useCatalogItems, useSaveCatalogItem } from "./api";
import { CatalogForm } from "./CatalogForm";
import { catalogToForm } from "./schema";

export function CatalogListView() {
  const router = useRouter();
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => { setDebounced(search); setPage(1); }, 300);
    return () => clearTimeout(t);
  }, [search]);

  const items = useCatalogItems({ search: debounced, type, page });
  const add = (
    <WriteGuard permission="catalog.manage">
      <Button onClick={() => router.push("/app/catalog/new")}>Add item</Button>
    </WriteGuard>
  );

  return (
    <>
      <PageHeader title="Products & Services" description="Reusable items for your invoices. Stock is not tracked in this version." actions={add} />
      <Card>
        <div className="flex flex-wrap gap-3 border-b border-border px-5 py-4">
          <label className="min-w-0 flex-1">
            <span className="sr-only">Search items</span>
            <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name, SKU or category" className="h-10 w-full rounded-lg border border-border px-3 text-sm focus:border-brand-500 focus:outline-none" />
          </label>
          <label>
            <span className="sr-only">Type</span>
            <select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-border bg-white px-3 text-sm">
              <option value="">Products and services</option>
              <option value="product">Products</option>
              <option value="service">Services</option>
            </select>
          </label>
        </div>
        {items.isPending ? (
          <PageSpinner />
        ) : items.isError ? (
          <ErrorState message={items.error.message} onRetry={() => void items.refetch()} />
        ) : items.data.data.length === 0 ? (
          <EmptyState title={debounced || type ? "No matching items" : "No items yet"} description="Save products and services you bill often to create invoices faster." action={!debounced && !type ? add : undefined} />
        ) : (
          <>
            <ul className="divide-y divide-border">
              {items.data.data.map((item) => (
                <li key={item.id}>
                  <Link href={`/app/catalog/${item.id}`} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-canvas/60">
                    <div className="min-w-0">
                      <p className="font-medium">{item.name}</p>
                      <p className="text-sm text-muted">
                        {item.type === "service" ? "Service" : "Product"} · per {item.unit}
                        {item.sku && <> · {item.sku}</>}
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      {!item.is_active && <Badge>Inactive</Badge>}
                      <span className="font-semibold tabular-nums">{formatBDT(item.price_minor)}</span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination page={items.data.meta.current_page} lastPage={items.data.meta.last_page} total={items.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}

export function CatalogCreateView() {
  const router = useRouter();
  const save = useSaveCatalogItem();
  return (
    <>
      <PageHeader title="Add product or service" />
      <Card>
        <CardBody>
          <CatalogForm submitLabel="Save item" onSubmit={async (payload) => { await save.mutateAsync(payload); router.replace("/app/catalog"); }} />
        </CardBody>
      </Card>
    </>
  );
}

export function CatalogEditView() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const item = useCatalogItem(id);
  const save = useSaveCatalogItem(id);
  const archive = useArchiveCatalogItem();
  const [saved, setSaved] = useState(false);

  if (item.isPending) return <PageSpinner />;
  if (item.isError) return <ErrorState message={item.error.message} onRetry={() => void item.refetch()} />;

  return (
    <>
      <PageHeader
        title={item.data.name}
        actions={
          <WriteGuard permission="catalog.manage">
            <Button
              variant="ghost"
              loading={archive.isPending}
              onClick={async () => {
                if (!window.confirm(`Archive ${item.data.name}?`)) return;
                await archive.mutateAsync(id);
                router.replace("/app/catalog");
              }}
            >
              Archive
            </Button>
          </WriteGuard>
        }
      />
      {saved && <Alert tone="success" className="mb-4">Item saved.</Alert>}
      <Card>
        <CardBody>
          <CatalogForm key={item.data.updated_at} initial={catalogToForm(item.data)} submitLabel="Save changes" onSubmit={async (payload) => { await save.mutateAsync(payload); setSaved(true); }} />
        </CardBody>
      </Card>
    </>
  );
}
