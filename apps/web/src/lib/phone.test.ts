import { describe, expect, it } from "vitest";
import { normalizeBangladeshMobile } from "./phone";

describe("normalizeBangladeshMobile", () => {
  it.each([
    ["01712345678", "+8801712345678"],
    ["017-1234-5678", "+8801712345678"],
    ["+880 1912 345678", "+8801912345678"],
    ["8801812345678", "+8801812345678"],
    ["1612345678", "+8801612345678"],
  ])("normalises %s", (input, expected) => {
    expect(normalizeBangladeshMobile(input)).toBe(expected);
  });

  it.each(["01212345678", "0171234567", "02-9876543", "hello", ""])("rejects %s", (input) => {
    expect(normalizeBangladeshMobile(input)).toBeNull();
  });
});
