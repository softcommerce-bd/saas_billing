import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api, ApiError, onSubscriptionMode, setApiTenant } from "./client";

function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });
}

describe("api client", () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
    document.cookie = "XSRF-TOKEN=token%3D123; path=/";
    setApiTenant(null);
  });

  afterEach(() => {
    fetchMock.mockReset();
    vi.unstubAllGlobals();
  });

  it("sends tenant, CSRF and idempotency headers with cookies", async () => {
    setApiTenant("01jtenant");
    fetchMock.mockResolvedValue(jsonResponse({ data: { id: "1" } }, 201));

    await api.post("subscription/orders", { plan_id: "p" }, "idem-key-123");

    const [url, init] = fetchMock.mock.calls.at(-1)!;
    expect(url).toBe("/api/v1/subscription/orders");
    expect(init.credentials).toBe("include");
    expect(init.headers["X-Tenant"]).toBe("01jtenant");
    expect(init.headers["X-XSRF-TOKEN"]).toBe("token=123");
    expect(init.headers["Idempotency-Key"]).toBe("idem-key-123");
  });

  it("builds query strings and skips empty values", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }));

    await api.get("customers", { search: "rahim", status: "", page: 2 });

    expect(fetchMock.mock.calls[0][0]).toBe("/api/v1/customers?search=rahim&page=2");
  });

  it("normalises the error envelope", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ error: { code: "SUBSCRIPTION_EXPIRED", message: "Renew your subscription to continue managing your business.", details: { subscription: { mode: "read_only" } } } }, 403),
    );

    const error = (await api.post("customers", {}).catch((e) => e)) as ApiError;

    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(403);
    expect(error.code).toBe("SUBSCRIPTION_EXPIRED");
    expect(error.message).toBe("Renew your subscription to continue managing your business.");
  });

  it("exposes field errors", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ error: { code: "VALIDATION_FAILED", message: "Fix fields", details: { fields: { mobile: ["Invalid"] } } } }, 422));

    const error = (await api.post("customers", {}).catch((e) => e)) as ApiError;

    expect(error.fieldErrors).toEqual({ mobile: ["Invalid"] });
  });

  it("notifies subscription mode listeners from response headers", async () => {
    const listener = vi.fn();
    const unsubscribe = onSubscriptionMode(listener);
    fetchMock.mockResolvedValue(jsonResponse({ data: [] }, 200, { "X-Subscription-Mode": "read_only" }));

    await api.get("customers");
    unsubscribe();

    expect(listener).toHaveBeenCalledWith("read_only");
  });

  it("refreshes CSRF cookie once and retries on token mismatch", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ error: { code: "CSRF_TOKEN_MISMATCH", message: "expired" } }, 419))
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // /sanctum/csrf-cookie
      .mockResolvedValueOnce(jsonResponse({ data: { ok: true } }));

    const result = await api.post<{ data: { ok: boolean } }>("customers", { name: "x" });

    expect(result.data.ok).toBe(true);
    expect(fetchMock.mock.calls[1][0]).toBe("/sanctum/csrf-cookie");
  });

  it("maps network failures to a friendly error", async () => {
    fetchMock.mockRejectedValue(new TypeError("Failed to fetch"));

    const error = (await api.get("customers").catch((e) => e)) as ApiError;

    expect(error.code).toBe("NETWORK_ERROR");
  });
});
