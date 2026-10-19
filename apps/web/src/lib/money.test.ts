import { describe, expect, it } from "vitest";
import { formatBDT, isValidAmount, minorToDecimalString, parseAmountToMinor } from "./money";

describe("money", () => {
  it("parses decimal strings to integer minor units without float drift", () => {
    expect(parseAmountToMinor("0.1")).toBe(10);
    expect(parseAmountToMinor("0.29")).toBe(29);
    expect(parseAmountToMinor("1250.5")).toBe(125050);
    expect(parseAmountToMinor("19.99")).toBe(1999);
  });

  it("rejects invalid amounts", () => {
    for (const bad of ["", "abc", "1.234", "-5", "1,000", "1e3"]) {
      expect(isValidAmount(bad)).toBe(false);
      expect(() => parseAmountToMinor(bad)).toThrow();
    }
  });

  it("formats with South Asian grouping and taka sign", () => {
    expect(formatBDT(123456750)).toBe("৳ 12,34,567.50");
    expect(formatBDT(99900)).toBe("৳ 999.00");
    expect(formatBDT(100000)).toBe("৳ 1,000.00");
    expect(formatBDT(-5000)).toBe("-৳ 50.00");
    expect(formatBDT(5, { symbol: false })).toBe("0.05");
    expect(formatBDT(null)).toBe("—");
  });

  it("round-trips", () => {
    for (const minor of [0, 1, 99, 100, 123456789]) {
      expect(parseAmountToMinor(minorToDecimalString(minor))).toBe(minor);
    }
  });
});
