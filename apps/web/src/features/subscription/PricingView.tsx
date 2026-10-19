"use client";

import Link from "next/link";
import { PlanCards } from "./PlanCards";

export function PricingView() {
  return (
    <PlanCards
      highlightCode="yearly"
      renderAction={() => (
        <Link href="/register" className="inline-flex h-10 w-full items-center justify-center rounded-lg bg-brand-600 text-sm font-medium text-white hover:bg-brand-700">
          Start free trial
        </Link>
      )}
    />
  );
}
