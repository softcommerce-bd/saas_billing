/**
 * Money is handled as integer minor units (poisha) end to end. These helpers only
 * convert between user-entered decimal strings and minor units for display/validation.
 * The server is always the authority for stored amounts.
 */

const AMOUNT_PATTERN = /^\d{1,13}(\.\d{1,2})?$/;

export function isValidAmount(input: string): boolean {
  return AMOUNT_PATTERN.test(input.trim());
}

export function parseAmountToMinor(input: string): number {
  const value = input.trim();
  if (!AMOUNT_PATTERN.test(value)) {
    throw new Error(`Invalid amount: ${input}`);
  }
  const [whole, fraction = ""] = value.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
}

export function minorToDecimalString(minor: number): string {
  const sign = minor < 0 ? "-" : "";
  const abs = Math.abs(minor);
  const whole = Math.floor(abs / 100);
  const fraction = String(abs % 100).padStart(2, "0");
  return `${sign}${whole}.${fraction}`;
}

/**
 * Bangladesh/South-Asian digit grouping: 12,34,567.50
 */
function groupSouthAsian(whole: string): string {
  if (whole.length <= 3) return whole;
  const last3 = whole.slice(-3);
  const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ",");
  return `${rest},${last3}`;
}

export function formatBDT(minor: number | null | undefined, options: { symbol?: boolean } = {}): string {
  if (minor === null || minor === undefined) return "—";
  const [whole, fraction] = minorToDecimalString(Math.abs(minor)).split(".");
  const sign = minor < 0 ? "-" : "";
  const symbol = options.symbol === false ? "" : "৳ ";
  return `${sign}${symbol}${groupSouthAsian(whole)}.${fraction}`;
}
