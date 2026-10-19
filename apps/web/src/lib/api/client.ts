/**
 * Thin fetch wrapper for the Laravel API (same origin via proxy).
 *
 *  - Session cookie auth (Sanctum SPA): credentials are cookies, never stored in JS.
 *  - CSRF: fetches /sanctum/csrf-cookie once and echoes XSRF-TOKEN as X-XSRF-TOKEN.
 *  - Tenant: sends X-Tenant for the selected company; the server re-verifies membership.
 *  - Errors: normalised to ApiError from the {"error": {code, message, details}} envelope.
 *  - Subscription mode: every tenant response carries X-Subscription-Mode; listeners
 *    are notified so the UI switches to read-only without a reload.
 */

export type ApiErrorCode =
  | "UNAUTHENTICATED" | "FORBIDDEN" | "NOT_FOUND" | "VALIDATION_FAILED" | "TOO_MANY_REQUESTS"
  | "CSRF_TOKEN_MISMATCH" | "CONFLICT" | "SERVER_ERROR" | "INVALID_CREDENTIALS" | "ACCOUNT_DISABLED"
  | "EMAIL_NOT_VERIFIED" | "TENANT_REQUIRED" | "TENANT_NOT_FOUND" | "TENANT_SUSPENDED" | "SUBSCRIPTION_EXPIRED"
  | "QUOTA_EXCEEDED" | "IDEMPOTENCY_KEY_REQUIRED" | "IDEMPOTENCY_KEY_REUSED" | "IDEMPOTENCY_REQUEST_IN_PROGRESS"
  | "PAYMENT_GATEWAY_UNAVAILABLE" | "PAYMENT_NOT_ALLOWED" | "NETWORK_ERROR";

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: ApiErrorCode,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** Field errors for forms: { field: [messages] }. */
  get fieldErrors(): Record<string, string[]> {
    const fields = this.details.fields;
    return fields && typeof fields === "object" ? (fields as Record<string, string[]>) : {};
  }
}

type ModeListener = (mode: string) => void;

const modeListeners = new Set<ModeListener>();
let tenantId: string | null = null;
let csrfReady: Promise<void> | null = null;

export function setApiTenant(id: string | null): void {
  tenantId = id;
}

export function onSubscriptionMode(listener: ModeListener): () => void {
  modeListeners.add(listener);
  return () => modeListeners.delete(listener);
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

async function ensureCsrfCookie(force = false): Promise<void> {
  if (force || !readCookie("XSRF-TOKEN")) csrfReady = null;
  csrfReady ??= fetch("/sanctum/csrf-cookie", { credentials: "include" }).then(() => undefined);
  return csrfReady;
}

export function newIdempotencyKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export type RequestOptions = {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  idempotencyKey?: string;
  signal?: AbortSignal;
};

export async function apiRequest<T>(path: string, options: RequestOptions = {}, retried = false): Promise<T> {
  const method = options.method ?? "GET";
  const mutating = method !== "GET";
  if (mutating) await ensureCsrfCookie();

  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
  }
  const base = path.startsWith("/") ? path : `/api/v1/${path}`;
  const url = params.size > 0 ? `${base}?${params.toString()}` : base;

  const headers: Record<string, string> = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  if (tenantId) headers["X-Tenant"] = tenantId;
  if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey;
  const xsrf = readCookie("XSRF-TOKEN");
  if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;

  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers,
      credentials: "include",
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    });
  } catch {
    throw new ApiError(0, "NETWORK_ERROR", "Cannot reach the server. Check your internet connection and try again.");
  }

  const mode = response.headers.get("X-Subscription-Mode");
  if (mode) modeListeners.forEach((listener) => listener(mode));

  if (response.status === 204) return undefined as T;

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const error = payload?.error;
    const apiError = new ApiError(
      response.status,
      (error?.code as ApiErrorCode) ?? "SERVER_ERROR",
      error?.message ?? "Something went wrong. Please try again.",
      error?.details ?? {},
    );

    // Expired CSRF token: refresh once and retry the same request.
    if (apiError.code === "CSRF_TOKEN_MISMATCH" && !retried) {
      await ensureCsrfCookie(true);
      return apiRequest<T>(path, options, true);
    }

    throw apiError;
  }

  return payload as T;
}

export const api = {
  get: <T>(path: string, query?: RequestOptions["query"], signal?: AbortSignal) => apiRequest<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown, idempotencyKey?: string) => apiRequest<T>(path, { method: "POST", body, idempotencyKey }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "PATCH", body }),
  delete: <T = void>(path: string) => apiRequest<T>(path, { method: "DELETE" }),
};
