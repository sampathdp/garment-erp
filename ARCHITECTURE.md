# Architecture

## 1. Overview

```
Browser (Next.js app, TypeScript)
   │  HTTPS, Sanctum SPA cookie session + CSRF (XSRF-TOKEN)
   ▼
Laravel API (modular monolith)  ──►  MySQL 8 (InnoDB)
   │                              ──►  Cache, sessions, locks, queues: database driver (shared hosting) or Redis (VPS)
   │                              ──►  Private disk outside the web root, or S3-compatible storage
   ├── Cron: schedule:run         ──►  queue processing, overdue gate passes, delivery-risk checks, cleanup
   └── Notifications              ──►  database; frontend polls (Reverb push only on a VPS)
```

- **Frontend:** `app.<domain>` — Next.js 16.4 (App Router, static export).
- **Backend:** `api.<domain>` — Laravel 12.71 on PHP 8.2+ (see §7 for the planned Laravel 13 upgrade). Both run on the **same registrable domain**, which Sanctum SPA cookie auth requires.
- **Deployment target:** **shared hosting** (client decision). The system is built so it runs on shared hosting and can move to a VPS later by changing configuration only. See §5 for the two deployment profiles and what shared hosting limits.

Installed versions are pinned in CLAUDE.md and in the lock files.

## 2. Repository layout

```
garment-erp/
├── backend/                 Laravel API
├── frontend/                Next.js app
├── .github/workflows/       CI: backend on SQLite + MySQL 8.4, frontend lint/type/test/build
├── docs/                    ERD.md, openapi.yaml
├── .tools/                  Local-only toolchain wrappers (git-ignored)
├── CLAUDE.md                Persistent instructions for AI-assisted development
└── *.md                     Architecture and standards documents
```

A single repository keeps API contract changes and UI changes in the same pull request.

## 3. Backend: modular monolith

### 3.1 Modules

| Module | Responsibility |
|---|---|
| `Identity` | Users, login/logout, password reset, roles/permissions, branch and page assignment |
| `Organizations` | Organization, branches, pages (Facebook pages), working calendar, holidays, sequences |
| `Customers` | Customer records, contact info, search |
| `Pricing` | Product types, options, sizes, price lists per page, price calculation |
| `Orders` | Orders, items, state machine, urgent approval, status history |
| `Payments` | Payment recording, verification, refunds, advance rule |
| `Invoices` | Invoice numbering, issuing, voiding, credit notes, PDF generation |
| `Design` | Design tasks, versions, files, reviews, correction requests |
| `Garments` | Name/number lists, versions, import/export, locking |
| `BulkProduction` | Production units, suppliers, bulk assignment (internal/external), measurements, checklists, production issues |
| `Printing` | Print preparation, samples, printing records |
| `Cutting` | Cutting jobs |
| `Sewing` | Sewing jobs (confirmed stage) |
| `QualityControl` | Inspections, defects, rework |
| `Delivery` | Deliveries, proof of delivery |
| `GatePass` | Gate passes and lines (used by Production, Printing and Delivery) |
| `Notifications` | Notification dispatch, preferences, realtime broadcasting |
| `Documents` | Secure file storage, upload/download, metadata |
| `Audit` | Append-only audit log |
| `Reporting` | Read-only queries and dashboards |

Gate pass is its own module because three other modules use it. `Reporting` has no code until Phase 13; a module directory is created only when it has content.

Shared kernel (`app/Support`): `Authorization` (BranchScoped, ScopedPolicy, AuthorizationTracker), `Http` (RequestId, SecurityHeaders, LimitRequestSize, RequireAuthorization, ApiResponse, ListQuery), `Exceptions` (ApiException, ApiExceptionRenderer), `Database` (CheckConstraint, MorphMap), `Enums` (HasValues), `Modules` (ModuleServiceProvider).

### 3.2 Module structure

```
backend/app/Modules/Orders/
├── Actions/            One class per use case: CreateOrder, SubmitOrder, TransitionOrder, ...
├── Data/               Typed input DTOs built from validated requests
├── Enums/              OrderStatus (backed enum + transition map), UrgentApprovalStatus
├── Events/             OrderSubmitted, OrderConfirmed, OrderStatusChanged
├── Exceptions/         InvalidTransition, AdvanceNotSatisfied
├── Http/
│   ├── Controllers/    Thin: authorize → validate → call action → return resource
│   ├── Requests/       FormRequest validation
│   └── Resources/      JsonResource output shaping
├── Jobs/
├── Listeners/
├── Models/             Eloquent models (explicit $fillable)
├── Policies/
├── Queries/            Read models for list/search endpoints (filters, eager loads)
├── routes.php          Module routes, loaded by the module service provider
└── OrdersServiceProvider.php
```

Rules:
- **Controllers** contain no business logic.
- **Actions** hold the business logic, own their transaction boundary, and are the only code that changes state.
- **Models** hold relations, casts and scopes only — no workflow logic in model events.
- **Cross-module communication** goes through another module's public Actions/Queries, or through domain events. A module never writes another module's tables directly. Reading another module's models through Eloquent relations is allowed.
- **Migrations** live in `backend/database/migrations` as one ordered history, not per module, so foreign keys resolve in order.
- No repository-interface layer over Eloquent. Add abstractions only where there are two real implementations (for example the storage driver or SMS provider).

### 3.3 Workflow engine
- `OrderStatus` is a PHP backed enum with an explicit allowed-transitions map.
- `TransitionOrder` runs: authorize → lock row → check version → evaluate guard → update status → write history → write audit → commit → dispatch events.
- Guards are small invokable classes per transition, so they can be unit tested.
- Long operations that touch several aggregates (for example invalidating print prep after a design re-approval) also take a cache lock: `Cache::lock("order:{id}", 10)` (database store on shared hosting, Redis on a VPS).

### 3.4 Authorization
- Spatie Laravel Permission with **permissions** as the unit of checking (`orders.view`, `payments.verify`, …). Roles are seeded bundles that admins can edit.
- Every model with business data has a `branch_id`, either directly or through its order (see DATABASE_DESIGN.md).
- The `BranchScoped` trait provides `->visibleTo($user)` (branch + page scope, deny by default). It is applied in every list query. Global access comes from the `scope.all_branches` / `scope.all_pages` permissions, never from role names.
- Policies extend `ScopedPolicy`: out of scope → 404, in scope without permission → 403.
- Route model binding is always followed by `Gate::authorize()`.
- The `authenticated` middleware group = `auth:sanctum` → `EnsureSessionIsValid` (active account, 12 h absolute lifetime) → `RequireAuthorization`, which fails any successful response for which no Gate check ran.

### 3.5 Authentication
- Sanctum **SPA stateful cookie** authentication. No bearer tokens are kept in browser storage.
- `SESSION_DOMAIN=.<domain>`, `SANCTUM_STATEFUL_DOMAINS=app.<domain>`, secure + HttpOnly + SameSite=Lax cookies, sessions stored in the database (shared hosting) or Redis (VPS).
- Login is rate limited. The session ID is regenerated on login. Inactive users are blocked on every request.
- Optional TOTP 2FA for Owner, Manager and Accounts roles (Phase 15).

### 3.6 Async and realtime
- Queues: `default`, `notifications`, `documents` (PDFs, thumbnails), `imports`. The driver comes from config: `database` on shared hosting, `redis` + Horizon on a VPS. Code never depends on a specific driver.
- On shared hosting, a cron entry runs `schedule:run` every minute. The scheduler starts `queue:work --stop-when-empty --max-time=50`, which processes jobs without a long-running process. Expect up to about one minute of delay for queued work.
- Events are dispatched **after commit** (`ShouldDispatchAfterCommit`), so listeners never see rolled-back data.
- Notifications: the database channel is always used. The frontend polls `GET /api/v1/notifications/unread-count` every 30 s (paused when the tab is hidden). Reverb broadcasting can be enabled later on a VPS without changing callers.
- Locks: `Cache::lock()` works with the `database` cache store on shared hosting and with Redis on a VPS. Workflow correctness never depends on the lock alone — row locks (`lockForUpdate`) are the main guard.

### 3.7 Files
- All business files go on a **private** disk. Downloads go through an authorized endpoint that returns a short-lived signed URL or streams the file.
- On shared hosting, the private disk is a folder **outside `public_html`**, or S3-compatible object storage if the client pays for it (recommended for design files and backups).
- With object storage, large design files are uploaded directly with presigned URLs. With local storage, uploads are chunked through the API (within the host's PHP `upload_max_filesize` and `post_max_size` limits).

## 4. Frontend: Next.js

### 4.1 Layout

```
frontend/src/
├── app/
│   ├── login/                      Public sign-in (Suspense around useSearchParams)
│   └── (app)/                      Authenticated shell (sidebar, top bar)
│       ├── dashboard/
│       ├── orders/  view/  new/        (record pages use ?id=, see §4.2)
│       ├── customers/
│       ├── payments/
│       ├── production/ printing/ cutting/ qc/ delivery/ gate-passes/
│       └── settings/ (users, roles, branches, pages, price lists)
├── app/providers.tsx               QueryClient (401 → login), ToastProvider, AuthProvider
├── features/<domain>/              api.ts (typed calls + query hooks), components, tests
│   ├── auth/                       AuthProvider/useAuth (can()), LoginForm, safeNextPath
│   ├── navigation/                 NAV_ITEMS + visibleNavItems (permission-aware), AppShell
│   ├── notifications/              NotificationBell (30 s polling)
│   └── organizations/              Branches/Pages tables (reference list screens)
├── components/ui/                  Button, TextField, DataTable, ConfirmDialog, Toast, Loading/Empty/Error states
├── lib/api-client.ts               fetch wrapper: credentials, CSRF + 419 retry, ApiError from envelope
├── lib/forms.ts                    applyServerErrors (422 → form fields)
└── lib/format.ts                   Money (minor units → LKR), dates in Asia/Colombo
```

### 4.2 Rules
- Authenticated pages are client components that fetch data with **TanStack Query**. Server components are used for static shell and layout only.
- **Static export (`output: 'export'`, `trailingSlash: true`)** so the frontend is plain HTML/JS/CSS that any shared host can serve. This rules out Proxy (the Next 16 name for middleware), Server Actions, route handlers, rewrites/headers, on-demand rendering, and **Cache Components / Partial Prefetching** (the build fails with "PPR cannot be enabled in export mode"), so none of them are used.
- Server Components are the default for pages and layouts (rendered at build time into the static shell). Client Components are used only where interaction or session data is needed (`"use client"` files under `features/` and `components/ui/`).
- Security headers and CSP for the frontend come from `public/.htaccess`, because `next.config` headers do not apply to a static export.
- Record pages use query parameters (`/orders/view?id=123`) instead of dynamic segments, because static export cannot generate a page per record. Hosting rewrites (`.htaccess`) send unknown paths to the app.
- Login redirects are done client-side: on a 401 from the API, the app goes to `/login`. This is UX only — the API enforces everything.
- The UI hides actions the user cannot perform, using the `abilities` the API returns with each resource. This is UX only, and the backend re-checks every action.
- Forms: React Hook Form + Zod. Server validation errors (422) are mapped back to fields.
- No business calculations in the frontend, except clearly labelled previews. The saved values always come from the API.
- Lists use server-side pagination, filtering and sorting.

## 5. Environments

| Environment | Notes |
|---|---|
| Local | WSL2 or Docker; MySQL 8, Mailpit; database drivers by default (matches production). Redis optional for testing profile B |
| CI | GitHub Actions with a MySQL 8 service; tests run with database drivers, plus one job with Redis to keep profile B working |
| Staging | Same hosting type as production (a subdomain on the same plan is acceptable), with anonymised data |
| Production | Shared hosting (profile A below), TLS, daily DB backups copied offsite, tested restore |

### 5.1 Deployment profiles

| Concern | A — Shared hosting (current) | B — VPS (later, config change only) |
|---|---|---|
| PHP | Host's PHP 8.3+ selector | PHP-FPM 8.3+ |
| Web | Apache + `.htaccess` on one origin (`garment-erp.codeplaystudios.com`): the root `.htaccess` routes `/api`, `/sanctum` and `/up` to `backend/public/index.php` and forbids every other `backend/` path; the settings file lives outside the web root (`<home>/envs/garment-erp.env`). See docs/DEPLOYMENT.md | Nginx |
| Frontend | Static export files in the subdomain root, next to `backend/` | Same, or a Node server |
| DB | Host MySQL 8 (MariaDB only if MySQL is unavailable — must be checked in Phase 01) | MySQL 8.4 |
| Cache / sessions / locks | `database` driver | Redis |
| Queues | `database` driver, processed by cron every minute | Redis + Horizon (Supervisor) |
| Scheduler | cron `* * * * * php artisan schedule:run` | Same |
| Realtime | Polling | Reverb (optional) |
| Files | Private folder outside `public_html`, or S3-compatible storage | S3-compatible storage |
| Virus scan | Not available — rely on type allow-list, re-encoding and download-only serving | ClamAV |
| Deploy | `.github/workflows/deploy.yml`: after CI passes on `master`, builds vendor + static frontend and uploads over FTPS (lftp); migrations are run on the server (SSH or cPanel Terminal) | Same pipeline, or zero-downtime releases |

### 5.2 Shared hosting requirements (Phase 01 must confirm, Q24)
PHP 8.3+ with `intl`, `sodium`, `pdo_mysql`, `fileinfo`, `bcmath`, `gd`; **SSH access**; **cron jobs** at 1-minute intervals; MySQL 8; subdomains with free TLS; the ability to place the Laravel app outside `public_html`. Without SSH and per-minute cron the system cannot run reliably, and a VPS is required.

### 5.3 Known limits of shared hosting
- Queued work (PDFs, notifications, imports) runs up to about one minute late.
- No realtime push; notifications arrive by polling.
- Large design uploads are limited by the host's PHP limits unless object storage is used.
- CPU and process limits on the plan can slow down reports and imports. Large imports are processed in chunks across several cron runs.
- No virus scanning.
- Shared IP and neighbours: no control over server-level security patches. Keep the application patched and backups offsite.

## 6. Key decisions

| Decision | Choice | Reason |
|---|---|---|
| Monolith vs microservices | Modular monolith | Single team, transactional workflow across modules |
| Money | BIGINT minor units | No floating-point errors; JSON-safe integers |
| State machine | Enum + action, no package | Small surface, fully testable |
| Auth | Sanctum SPA cookies | No tokens in JS-readable storage |
| Status columns | VARCHAR + PHP enum, not MySQL ENUM | Adding states needs no table rebuild |
| Audit | Custom append-only table | Domain-specific actions, not only model diffs |
| Hosting | Shared hosting first, VPS-ready through config | Client decision; drivers are swappable |
| Frontend build | Next.js static export | Runs on shared hosting without a Node server |
| Tenancy | Single company, many branches | Client confirmed; no tenant layer |
| Framework version | Laravel 12 now, Laravel 13 later | Local PHP is 8.2 and Laravel 13 needs PHP 8.3+. See §7 |
| Audit log vs activity log | One `audit_logs` table | The activity feed is a query over the audit trail |
| Authorization safety net | `RequireAuthorization` middleware + route coverage test | A forgotten policy call fails tests instead of leaking data |

## 7. Known technical debt (Phase 01)

| Item | Impact | Plan |
|---|---|---|
| Laravel 12.71, not 13.x | Laravel 12 security fixes end around Feb 2027 | Upgrade in Phase 02, after PHP 8.3+ is installed locally and confirmed on the host (Q24). Code uses no APIs removed in 13 |
| Tests run on SQLite locally | CHECK constraints and audit triggers are not exercised locally | CI runs the suite on MySQL 8.4 as well. Not yet run: the repository has no remote |
| Frontend CSP allows `'unsafe-inline'` scripts | Weaker XSS defence | Phase 15: build-time script hashes |
| No E2E (Playwright) yet | Full browser flow verified only by a manual curl smoke test | Add in Phase 02 together with the user-management screens |
| ~~Login timing differs for unknown and known emails~~ | Resolved in Phase 02: `SessionGuard::attempt()` and the password broker run inside Laravel's `Timebox` (fixed minimum duration) | — |
