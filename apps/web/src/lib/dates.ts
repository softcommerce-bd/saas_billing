const BUSINESS_TZ = "Asia/Dhaka";

/** Formats an ISO instant as a date in Asia/Dhaka, e.g. "31 Dec 2027". */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TZ, day: "numeric", month: "short", year: "numeric" }).format(new Date(iso));
}

/** Formats a plain local date ("2027-12-31") without timezone shifting. */
export function formatLocalDate(date: string | null | undefined): string {
  if (!date) return "—";
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", day: "numeric", month: "short", year: "numeric" }).format(new Date(Date.UTC(y, m - 1, d)));
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TZ, dateStyle: "medium", timeStyle: "short" }).format(new Date(iso));
}
