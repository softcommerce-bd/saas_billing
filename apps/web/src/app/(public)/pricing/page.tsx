import type { Metadata } from "next";
import { PricingView } from "@/features/subscription/PricingView";

export const metadata: Metadata = { title: "Pricing" };

export default function PricingPage() {
  return (
    <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
      <h1 className="text-3xl font-semibold tracking-tight">Simple packages</h1>
      <p className="mt-2 max-w-2xl text-muted">Every package includes the same features. Choose how long you want to pay for. Prices in BDT.</p>
      <div className="mt-10">
        <PricingView />
      </div>
    </div>
  );
}
