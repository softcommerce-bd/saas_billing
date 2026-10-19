import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = { title: "Features" };

// Availability labels are kept honest: "Available" only for shipped modules.
const modules = [
  { name: "Company accounts & team roles", status: "Available", body: "Owners, admins, accounts, billing and collection staff with separate permissions." },
  { name: "Customer management", status: "Available", body: "Individual and business customers, Bangladesh mobile numbers, opening balances, CSV export." },
  { name: "Products & services catalog", status: "Available", body: "Reusable items with units, prices, default discounts and configurable tax labels." },
  { name: "Subscriptions with bKash", status: "Available (gateway activation pending)", body: "Choose monthly, quarterly, half-yearly or yearly packages and renew online." },
  { name: "Invoices & PDF", status: "In development", body: "Fast invoice builder, A4 PDF with Bangla fonts, secure share links." },
  { name: "Payments & collections", status: "In development", body: "Partial payments, allocations across invoices, today's collection queue." },
  { name: "SMS & email reminders", status: "Planned", body: "Configurable Bangla/English reminders with delivery tracking." },
  { name: "Reports", status: "Planned", body: "Daily collection, receivables aging, customer statements and more." },
];

export default function FeaturesPage() {
  return (
    <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
      <h1 className="text-3xl font-semibold tracking-tight">Features</h1>
      <p className="mt-2 max-w-2xl text-muted">Built for Bangladeshi businesses: BDT, Asia/Dhaka time, Bangla and English.</p>
      <div className="mt-10 grid gap-4 sm:grid-cols-2">
        {modules.map((m) => (
          <div key={m.name} className="rounded-card border border-border bg-white p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-semibold">{m.name}</h2>
              <span className="text-xs font-medium text-muted">{m.status}</span>
            </div>
            <p className="mt-2 text-sm text-muted">{m.body}</p>
          </div>
        ))}
      </div>
      <Link href="/register" className="mt-10 inline-flex h-11 items-center rounded-lg bg-brand-600 px-5 font-medium text-white hover:bg-brand-700">
        Create your account
      </Link>
    </div>
  );
}
