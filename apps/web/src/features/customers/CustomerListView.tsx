"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { formatBDT } from "@/lib/money";
import { usePermission } from "@/lib/session/SessionProvider";
import { WriteGuard } from "@/components/subscription/WriteGuard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Pagination } from "@/components/ui/Pagination";
import { PageSpinner } from "@/components/ui/Spinner";
import { useCustomers } from "./api";

export function CustomerListView() {
  const router = useRouter();
  const params = useSearchParams();
  const canExport = usePermission("data.export");
  const urlSearch = params.get("search") ?? "";
  const [search, setSearch] = useState(urlSearch);
  const [syncedUrlSearch, setSyncedUrlSearch] = useState(urlSearch);
  const [debounced, setDebounced] = useState(search);
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);

  // Topbar search navigates here with ?search=; adopt it when the URL changes.
  if (urlSearch !== syncedUrlSearch) {
    setSyncedUrlSearch(urlSearch);
    setSearch(urlSearch);
  }
  useEffect(() => {
    const t = setTimeout(() => {
      setDebounced(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [search]);

  const customers = useCustomers({ search: debounced, status, page, per_page: 25 });

  return (
    <>
      <PageHeader
        title="Customers"
        description="People and businesses you bill."
        actions={
          <>
            {canExport && (
              <a href={`/api/v1/customers/export${debounced ? `?search=${encodeURIComponent(debounced)}` : ""}`} className="inline-flex h-10 items-center rounded-lg border border-border bg-white px-4 text-sm font-medium hover:bg-canvas" download>
                Export CSV
              </a>
            )}
            <WriteGuard permission="customers.manage">
              <Button onClick={() => router.push("/app/customers/new")}>Add customer</Button>
            </WriteGuard>
          </>
        }
      />
      <Card>
        <div className="flex flex-wrap gap-3 border-b border-border px-5 py-4">
          <label className="min-w-0 flex-1">
            <span className="sr-only">Search customers</span>
            <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name, company, mobile or email" className="h-10 w-full rounded-lg border border-border px-3 text-sm focus:border-brand-500 focus:outline-none" />
          </label>
          <label>
            <span className="sr-only">Status</span>
            <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-border bg-white px-3 text-sm">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>
        </div>

        {customers.isPending ? (
          <PageSpinner label="Loading customers…" />
        ) : customers.isError ? (
          <ErrorState message={customers.error.message} onRetry={() => void customers.refetch()} />
        ) : customers.data.data.length === 0 ? (
          <EmptyState
            title={debounced || status ? "No matching customers" : "No customers yet"}
            description={debounced || status ? "Try a different search or filter." : "Add your first customer to start billing."}
            action={
              !debounced && !status ? (
                <WriteGuard permission="customers.manage">
                  <Button onClick={() => router.push("/app/customers/new")}>Add customer</Button>
                </WriteGuard>
              ) : undefined
            }
          />
        ) : (
          <>
            {/* Desktop table */}
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full text-left text-sm">
                <thead className="bg-canvas text-xs uppercase tracking-wide text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-3 font-medium">Customer</th>
                    <th scope="col" className="px-5 py-3 font-medium">Mobile</th>
                    <th scope="col" className="px-5 py-3 font-medium">District</th>
                    <th scope="col" className="px-5 py-3 text-right font-medium">Opening balance</th>
                    <th scope="col" className="px-5 py-3 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {customers.data.data.map((c) => (
                    <tr key={c.id} className="hover:bg-canvas/60">
                      <td className="px-5 py-3">
                        <Link href={`/app/customers/${c.id}`} className="font-medium text-ink hover:text-brand-700">
                          {c.name}
                        </Link>
                        {c.company_name && <p className="text-xs text-muted">{c.company_name}</p>}
                      </td>
                      <td className="px-5 py-3 tabular-nums">{c.mobile_display ?? "—"}</td>
                      <td className="px-5 py-3">{c.district ?? "—"}</td>
                      <td className="px-5 py-3 text-right tabular-nums">{formatBDT(c.opening_balance_minor)}</td>
                      <td className="px-5 py-3">
                        <Badge tone={c.status === "active" ? "success" : "neutral"}>{c.status === "active" ? "Active" : "Inactive"}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {/* Mobile cards */}
            <ul className="divide-y divide-border md:hidden">
              {customers.data.data.map((c) => (
                <li key={c.id}>
                  <Link href={`/app/customers/${c.id}`} className="flex items-center justify-between gap-3 px-5 py-4">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{c.name}</p>
                      <p className="text-sm text-muted">{c.mobile_display ?? c.email ?? "No contact"}</p>
                    </div>
                    <Badge tone={c.status === "active" ? "success" : "neutral"}>{c.status === "active" ? "Active" : "Inactive"}</Badge>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination page={customers.data.meta.current_page} lastPage={customers.data.meta.last_page} total={customers.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}
