import { describe, expect, it } from "vitest";
import type { Plan } from "@/lib/api/types";
import { savingsPercent } from "./PlanCards";

const plan = (code: Plan["code"], months: number, price: number): Plan => ({
  id: code, code, name: code, description: null, duration_months: months, price_minor: price, price: "", monthly_equivalent_minor: Math.floor(price / months), currency: "BDT", is_active: true, entitlements: null,
});

describe("savingsPercent", () => {
  it("compares against paying monthly", () => {
    const monthly = plan("monthly", 1, 100000);
    expect(savingsPercent(plan("yearly", 12, 1020000), monthly)).toBe(15);
    expect(savingsPercent(plan("quarterly", 3, 285000), monthly)).toBe(5);
    expect(savingsPercent(monthly, monthly)).toBe(0);
    expect(savingsPercent(plan("yearly", 12, 1300000), monthly)).toBe(0);
  });
});
