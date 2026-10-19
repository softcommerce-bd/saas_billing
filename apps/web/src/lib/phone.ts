/**
 * Client-side mirror of the API's Bangladesh mobile normalisation, used for instant
 * validation feedback. The API re-validates and stores the canonical value.
 */
export function normalizeBangladeshMobile(input: string): string | null {
  let digits = input.trim().replace(/[\s\-().]/g, "");
  if (digits === "" || !/^\+?\d+$/.test(digits)) return null;

  digits = digits.replace(/^\+/, "");
  if (digits.startsWith("00880")) digits = digits.slice(4);
  else if (digits.startsWith("880")) digits = digits.slice(3);
  if (digits.length === 10 && digits.startsWith("1")) digits = `0${digits}`;

  return /^01[3-9]\d{8}$/.test(digits) ? `+88${digits}` : null;
}
