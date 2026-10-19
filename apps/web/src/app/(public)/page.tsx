import Link from "next/link";
import { productTagline } from "@/lib/config";

const highlights = [
  { title: "Fast billing", body: "Create invoices for products, services or both, with BDT amounts, Bangla and English text." },
  { title: "Every payment tracked", body: "Record cash, bank, bKash, Nagad, Rocket and cheque payments against invoices." },
  { title: "Smarter collections", body: "See what is due today, what is overdue and who to follow up with next." },
];

export default function LandingPage() {
  return (
    <>
      <section className="bg-navy-900 text-white">
        <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
          <p className="text-sm font-medium uppercase tracking-wider text-brand-100">For small businesses in Bangladesh</p>
          <h1 className="mt-4 max-w-3xl text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">{productTagline}</h1>
          <p className="mt-6 max-w-2xl text-lg text-white/75">
            Professional invoicing, customer dues and collection management for offices, agencies, traders, wholesalers and service
            providers.
          </p>
          <div className="mt-10 flex flex-wrap gap-3">
            <Link href="/register" className="inline-flex h-12 items-center rounded-lg bg-brand-500 px-6 font-medium text-white hover:bg-brand-600">
              Start free trial
            </Link>
            <Link href="/pricing" className="inline-flex h-12 items-center rounded-lg border border-white/30 px-6 font-medium text-white hover:bg-white/10">
              See pricing
            </Link>
          </div>
        </div>
      </section>
      <section className="mx-auto grid max-w-6xl gap-6 px-4 py-16 sm:grid-cols-3 sm:px-6">
        {highlights.map((h) => (
          <div key={h.title} className="rounded-card border border-border bg-white p-6">
            <h2 className="text-lg font-semibold text-ink">{h.title}</h2>
            <p className="mt-2 text-sm text-muted">{h.body}</p>
          </div>
        ))}
      </section>
    </>
  );
}
