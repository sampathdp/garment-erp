import { describe, expect, it, vi } from "vitest";
import { ApiError, apiRequest, buildUrl } from "./api-client";

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });
}

describe("apiRequest", () => {
  it("sends cookies and JSON headers on GET without touching CSRF", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(jsonResponse(200, { data: { ok: true } }));

    await expect(apiRequest("health")).resolves.toEqual({ data: { ok: true } });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/\/api\/v1\/health$/);
    expect(init?.credentials).toBe("include");
    expect((init?.headers as Record<string, string>).Accept).toBe("application/json");
    expect((init?.headers as Record<string, string>)["X-XSRF-TOKEN"]).toBeUndefined();
  });

  it("fetches the CSRF cookie and sends X-XSRF-TOKEN on state-changing requests", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
      if (String(input).endsWith("/sanctum/csrf-cookie")) {
        document.cookie = "XSRF-TOKEN=abc%3D123; path=/";
        return new Response(null, { status: 204 });
      }
      return jsonResponse(201, { data: { id: 1 } });
    });

    await apiRequest("things", { method: "POST", body: { a: 1 } });

    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/sanctum\/csrf-cookie$/);
    const headers = fetchMock.mock.calls[1][1]?.headers as Record<string, string>;
    expect(headers["X-XSRF-TOKEN"]).toBe("abc=123");
    expect(headers["Content-Type"]).toBe("application/json");
  });

  it("refreshes the CSRF cookie and retries once on 419", async () => {
    document.cookie = "XSRF-TOKEN=stale; path=/";
    let calls = 0;
    vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
      if (String(input).endsWith("/sanctum/csrf-cookie")) return new Response(null, { status: 204 });
      calls += 1;
      return calls === 1 ? jsonResponse(419, { message: "CSRF token mismatch.", code: "csrf_token_mismatch" }) : jsonResponse(200, { data: {} });
    });

    await expect(apiRequest("things", { method: "POST" })).resolves.toEqual({ data: {} });
    expect(calls).toBe(2);
  });

  it("turns the error envelope into an ApiError with field errors and request id", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse(422, { message: "The given data was invalid.", code: "validation_failed", errors: { email: ["Required"] }, request_id: "req-1" }),
    );

    const error = await apiRequest("auth/me").catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 422, code: "validation_failed", fieldErrors: { email: ["Required"] }, requestId: "req-1" });
    expect((error as ApiError).isValidation).toBe(true);
  });

  it("never surfaces non-JSON error bodies", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("<html>Hosting error</html>", { status: 502 }));

    const error = (await apiRequest("health").catch((e: unknown) => e)) as ApiError;
    expect(error.message).toBe("Something went wrong. Please try again.");
    expect(error.message).not.toContain("html");
  });

  it("reports network failures as a typed error", async () => {
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new TypeError("Failed to fetch"));

    await expect(apiRequest("health")).rejects.toMatchObject({ status: 0, code: "network_error" });
  });

  it("returns undefined for 204", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(null, { status: 204 }));
    document.cookie = "XSRF-TOKEN=t; path=/";

    await expect(apiRequest("auth/logout", { method: "POST" })).resolves.toBeUndefined();
  });
});

describe("buildUrl", () => {
  it("drops empty query values and encodes the rest", () => {
    const url = new URL(buildUrl("branches", { page: 2, sort: "-name", search: "", x: undefined }));
    expect(url.searchParams.get("page")).toBe("2");
    expect(url.searchParams.get("sort")).toBe("-name");
    expect(url.searchParams.has("search")).toBe(false);
  });
});
