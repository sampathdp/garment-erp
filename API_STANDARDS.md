# API Standards

These rules apply to every endpoint of the Laravel API. The Phase 01 endpoints (auth, branches, pages, notifications, health) are the reference implementation, and `docs/openapi.yaml` describes them.

## 1. Base URL and versioning
- All endpoints live under `/api/v1`. Module routes are registered by each module's service provider (`app/Modules/<Module>/routes.php`), and `routes/api.php` holds only cross-cutting routes (health).
- A breaking change (removed field, changed type or meaning, new required input) needs `/api/v2` for the affected resources. Additive changes (new optional fields, new endpoints) stay in v1.
- `GET /sanctum/csrf-cookie` (outside `/api`) issues the CSRF cookie.

## 2. Authentication, CSRF and CORS
- Browsers use **Sanctum SPA cookie sessions** only. No tokens are kept in JavaScript-readable storage.
- Flow: `GET /sanctum/csrf-cookie` → `POST /api/v1/auth/login` with the `X-XSRF-TOKEN` header (the value of the `XSRF-TOKEN` cookie) → the session cookie (HttpOnly, Secure in production, SameSite=Lax) is set and the session ID is regenerated.
- Every state-changing request sends `X-XSRF-TOKEN`. On `419` the client fetches a fresh cookie and retries once (`frontend/src/lib/api-client.ts`).
- Login is accepted only from origins in `SANCTUM_STATEFUL_DOMAINS`. Other origins get `403 stateful_origin_required`.
- CORS allows only the configured frontend origin(s), with credentials. The allowed request headers are `Accept, Content-Type, X-Requested-With, X-XSRF-TOKEN, X-Request-Id, Idempotency-Key`.

## 3. Requests
- JSON bodies (`Content-Type: application/json`), up to 1 MB (`413 payload_too_large` beyond that). Uploads use `multipart/form-data` with per-category limits (`DocumentCategory`).
- Every input is validated by a FormRequest (or `$request->validate`) with explicit types and maximum lengths. Unknown fields are ignored. Validated data reaches Actions only as DTOs or arrays from `validated()`, never `$request->all()`.
- Route parameters are numeric (`whereNumber`). Binding a model is always followed by an authorization check.
- Naming: plural nouns for resources (`/branches`, `/orders/{order}/payments`) and snake_case JSON keys.
- Workflow actions are explicit sub-resources, for example `POST /orders/{order}/transitions` with `{"to": "design_review", "version": 4}` or `POST /payments/{payment}/verify`. Generic PATCHes never change status.
- Concurrency: aggregates that are edited concurrently send their `version`. A stale version returns `409 version_conflict`.
- Idempotency: `POST` endpoints that create money records accept `Idempotency-Key: <uuid>`. Repeating the key returns the original result.

## 4. Responses

Single resource:
```json
{ "data": { "id": 1, "code": "CMB", "name": "Colombo" } }
```

Paginated list (`ApiResponse::paginated`):
```json
{
  "data": [ { "id": 1 } ],
  "meta": { "page": 1, "per_page": 25, "total": 42, "last_page": 2 },
  "links": { "next": "https://…?page=2", "prev": null }
}
```

Error envelope (`ApiExceptionRenderer`):
```json
{ "message": "The given data was invalid.", "code": "validation_failed",
  "errors": { "email": ["The email field is required."] },
  "request_id": "0b6f…" }
```

| Status | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | No or expired session (including the absolute session lifetime) |
| 403 | `forbidden` | Authenticated, record in scope, permission missing |
| 403 | `account_inactive` | User deactivated; the session is ended |
| 403 | `stateful_origin_required` | Login from an origin that is not a configured frontend |
| 404 | `not_found` | Unknown route or record, **or a record outside the user's branch/page scope** |
| 409 | `conflict`, `invalid_transition`, `version_conflict` | Workflow state or optimistic lock conflict (`ApiException::conflict`) |
| 413 | `payload_too_large` | Body over the limit |
| 419 | `csrf_token_mismatch` | Missing or invalid CSRF token |
| 422 | `validation_failed` | Field errors in `errors` |
| 429 | `too_many_requests` | Rate limit; `Retry-After` header |
| 500 | `server_error` | Generic message only; details go to the log under `request_id` |

- `message` is safe to show to users. Internal exception messages, SQL and stack traces are never returned (a `debug` block appears only when `APP_DEBUG=true`, which `app:security-check` forbids in production).
- Every response carries `X-Request-Id`. Clients may send a UUID `X-Request-Id`; anything else is replaced.
- Successful deletes and logout return `204` with no body.

## 5. Data formats

| Kind | Format |
|---|---|
| Money | Integer minor units in `*_minor` fields (`"total_minor": 1234550`) plus `currency`. Display formatting is done by the client (`formatMoney`). |
| Timestamps | ISO 8601 UTC with `Z` (`2026-11-08T19:21:56Z`) |
| Business dates | `YYYY-MM-DD` (an Asia/Colombo calendar date, never timezone-shifted) |
| Enums | Lower snake_case strings matching the PHP backed enums |
| IDs | Integers. Human numbers (`order_no`, `invoice_no`) are separate string fields |

Detail resources will include an `abilities` object (for example `{"verify": true, "void": false}`) computed by the policies, so the UI can hide actions. The API still re-checks every action.

## 6. Lists: pagination, filtering, sorting
- `?page=` (from 1) and `?per_page=` (default 25, maximum 100; anything else → 422).
- `?sort=field` or `?sort=-field`. Fields come from a per-endpoint whitelist (`ListQuery::applySort`), and unknown fields → 422. User input is never interpolated into SQL.
- Filters use `?filter[name]=value` and are validated like any other input. `LIKE` searches escape `%` and `_`.
- Every list query applies `->visibleTo($user)` (branch and page scope) before filters.
- Columns are selected explicitly, and relations are eager-loaded in the Query class (N+1 queries throw outside production).

## 7. Authorization contract
- Every route except `health` and `auth.login` sits in the `authenticated` middleware group: `auth:sanctum` → `EnsureSessionIsValid` (active account, absolute lifetime) → `RequireAuthorization`.
- `RequireAuthorization` fails any successful response for which no Gate/policy check ran (it throws outside production and returns 403 in production). Routes that act only on the caller's own data opt out explicitly, and `RouteAuthorizationTest` lists them: `auth.me`, `auth.logout`, `notifications.*`.
- Policies extend `ScopedPolicy`: a record outside scope → 404 (`denyAsNotFound`), a record in scope without the permission → 403.
- Code checks permissions (`orders.view`), never role names.

## 8. Rate limits

| Limiter | Limit |
|---|---|
| `login` | 5/min per email+IP and 20/min per IP |
| `api` | 120/min per user (60/min per IP unauthenticated) |
| `uploads` | 20/min per user |
| `exports` | 10/min per user |

## 9. OpenAPI conventions
- `docs/openapi.yaml` (OpenAPI 3.1) is updated **in the same pull request** as any endpoint change.
- Each operation has an `operationId` equal to the Laravel route name (`branches.index`), a `tags` entry equal to the module, the `x-permission` extension naming the permission checked, and responses that reference the shared `Error` schema for 401/403/404/422/429.
- Shared components: `Error`, `PaginationMeta`, `PaginationLinks`, and the `perPage`, `page` and `sort` parameters.
- Schema names follow the Resource class names (`BranchResource` → `Branch`).

## 10. Tests required per endpoint
Happy path, 422 validation, 401 unauthenticated, 403 missing permission, 404 for another branch or page, 409 for invalid state where relevant, and a query-count assertion on list endpoints.
