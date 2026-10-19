import { Button } from "./Button";

export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (page: number) => void }) {
  if (lastPage <= 1) return <p className="px-5 py-3 text-sm text-muted">{total} total</p>;
  return (
    <nav aria-label="Pagination" className="flex items-center justify-between gap-3 border-t border-border px-5 py-3">
      <p className="text-sm text-muted">
        Page {page} of {lastPage} · {total} total
      </p>
      <div className="flex gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </Button>
        <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          Next
        </Button>
      </div>
    </nav>
  );
}
