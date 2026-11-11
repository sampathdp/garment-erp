/**
 * Single HTTP layer for the Laravel API (API_STANDARDS.md).
 *
 * - Sanctum SPA cookie auth: `credentials: "include"`, never a token in storage.
 * - CSRF: state-changing requests send X-XSRF-TOKEN from the XSRF-TOKEN cookie,
 *   fetching /sanctum/csrf-cookie first when needed and retrying once on 419.
 * - Errors are normalised from the API envelope into ApiError.
 */

export const API_BASE_URL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");

export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fieldErrors: FieldErrors;
  readonly requestId: string | null;

  constructor(status: number, code: string, message: string, fieldErrors: FieldErrors = {}, requestId: string | null = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.code = code;
    this.fieldErrors = fieldErrors;
    this.requestId = requestId;
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number; last_page: number };
  links: { next: string | null; prev: string | null };
}

export interface ApiRequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  signal?: AbortSignal;
  headers?: Record<string, string>;
}

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

export function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.split("; ").find((part) => part.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie("XSRF-TOKEN")) return;
  await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } });
}

export function buildUrl(path: string, query?: ApiRequestOptions["query"]): string {
  const url = new URL(`${API_BASE_URL}/api/v1/${path.replace(/^\//, "")}`);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
  }
  return url.toString();
}

async function toApiError(response: Response): Promise<ApiError> {
  let body: { message?: unknown; code?: unknown; errors?: unknown; request_id?: unknown } = {};
  try {
    body = (await response.json()) as typeof body;
  } catch {
    // Non-JSON error (proxy/hosting page). Never surface raw HTML to users.
  }
  const message = typeof body.message === "string" ? body.message : "Something went wrong. Please try again.";
  const code = typeof body.code === "string" ? body.code : "http_error";
  const errors = body.errors && typeof body.errors === "object" ? (body.errors as FieldErrors) : {};
  const requestId = typeof body.request_id === "string" ? body.request_id : response.headers.get("X-Request-Id");
  return new ApiError(response.status, code, message, errors, requestId);
}

export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}, retried = false): Promise<T> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...options.headers,
  };

  if (!SAFE_METHODS.has(method)) {
    await ensureCsrfCookie();
    const token = readCookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }

  let body: BodyInit | undefined;
  if (options.body instanceof FormData) {
    body = options.body;
  } else if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(options.body);
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), { method, headers, body, credentials: "include", signal: options.signal });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new ApiError(0, "network_error", "Cannot reach the server. Check your connection.");
  }

  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true);
    return apiRequest<T>(path, options, true);
  }

  if (!response.ok) throw await toApiError(response);
  if (response.status === 204) return undefined as T;
  return (await response.json()) as T;
}

/**
 * Fetches a server-generated document (for example a printable HTML page) as a Blob, with the
 * same credentials and error envelope handling as apiRequest. The server decides whether the
 * document may be generated; errors surface as ApiError.
 */
export async function apiBlob(path: string, accept = "text/html", signal?: AbortSignal): Promise<Blob> {
  let response: Response;
  try {
    response = await fetch(buildUrl(path), { headers: { Accept: `${accept}, application/json`, "X-Requested-With": "XMLHttpRequest" }, credentials: "include", signal });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new ApiError(0, "network_error", "Cannot reach the server. Check your connection.");
  }
  if (!response.ok) throw await toApiError(response);
  return response.blob();
}

export const api = {
  get: <T>(path: string, query?: ApiRequestOptions["query"], signal?: AbortSignal) => apiRequest<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "POST", body }),
  put: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "PUT", body }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "PATCH", body }),
  delete: <T>(path: string) => apiRequest<T>(path, { method: "DELETE" }),
};
