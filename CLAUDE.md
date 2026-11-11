# CLAUDE.md — Garment Manufacturing & Production Management System

Persistent instructions for AI-assisted development in this repository. Read this file first in every session. Detailed references:
ARCHITECTURE.md · DATABASE_DESIGN.md · API_STANDARDS.md · SECURITY_STANDARDS.md · PERFORMANCE_STANDARDS.md · WORKFLOW_REQUIREMENTS.md · IMPLEMENTATION_ROADMAP.md · PROJECT_AUDIT.md · docs/ERD.md · docs/openapi.yaml

## Current status
- Phase 00 (documentation), **Phase 01 (foundation)**, **Phase 02 (authentication, users, roles, permissions, branches/pages, central policies)** and **Phase 03 (customers, order intake, job numbers, seven-day rule with dual urgent approval, payment verification, 30% advance, Approved Orders, intake notifications)** are complete. Design, garment details and production start in later phases.
- **Phase 04** is complete: job details after payment approval, design tasks (configured responsible designer, design plan by due date), immutable design versions with files / allow-listed links (never fetched), correction rounds with a configurable limit, Page-Owner-only final approval and final files, configurable name/number list fields with review, confirmation lock and administrative correction, rule-based page pricing with effective dates, immutable invoice snapshots, and automatic one-per-order bulk jobs.
- **Phase 05** is complete: bulk management (WORKFLOW_REQUIREMENTS.md §3.7b) — Operation Admin and Bulk Designer dashboards, internal/external assignment with history and restricted external edits, configurable measurements (bulk meter, patch meter) with change history and post-completion correction, per-job checklist instances with client confirmation, Page Owner review with correction rounds and immutable versions, list locking, internal final submission with WeTransfer link, external completion, live Job Card read model. Completion moves the order to `print_preparation` and opens the printing job.
- **Phase 06** is complete: printing management (WORKFLOW_REQUIREMENTS.md §3.9a) — printing jobs opened at bulk completion (`OpenPrintJob`, one per bulk job), printing dashboard, send for printing, sample upload (content-checked images), Page Owner sample approval/rejection with mandatory reason, immutable sample versions, controlled statuses (`PrintingWorkflow::transition()`) up to Printing Completed, server-side lock on printing the Job Card / name list until sample approval (`printDocuments`, 409 `document_printing_locked`), gate pass details after physical printing.
- **Phase 07** is complete: cutting, quality checks and production issues (WORKFLOW_REQUIREMENTS.md §3.11a) — cutting jobs opened once per printing job by the first gate pass (`OpenCuttingJob`, order → `cutting`), cutting dashboard, cutter assignment with history, configurable cutting checklist (responsible user, client confirmation) and quality checks (mandatory must pass), cut quantities per size, central production issue register (`ReportProductionIssue`, `ManageProductionIssue`, blocking flag, notifications), completion (`CompleteCuttingJob`, `CuttingCompletion::blockers()`) moves the order to `sewing`. Phase 08 adds the minimal sewing step before delivery (user decision 2026-11-11).
- Phase 03 scope came from the user's Phase 03 brief, which differs from the original roadmap order (documents/pricing moved later; see IMPLEMENTATION_ROADMAP.md).
- Open items: client questions Q22 and Q25–Q29 (WORKFLOW_REQUIREMENTS.md §6.2), Playwright E2E, the Laravel 13 upgrade (needs PHP 8.3+), holiday calendar screen, price lists (unit price is entered on the order form for now).
- Do not start a phase unless the user explicitly asks for it. Check IMPLEMENTATION_ROADMAP.md for the phase scope and its blocking client questions.

## Pinned versions (Phase 01)
| Backend | Version | Frontend | Version |
|---|---|---|---|
| PHP (local) | 8.2.12 | Node | 22 |
| laravel/framework | 12.71 (upgrade to 13 planned, ARCHITECTURE.md §7) | next | 16.4.5 |
| laravel/sanctum | 4.3 | react / react-dom | 19.3 |
| spatie/laravel-permission | 6.25 | @tanstack/react-query | 5.107 |
| predis/predis | 3.7 (profile B only) | react-hook-form / zod | 7.91 / 4.6 |
| pestphp/pest | 3.8 | vitest | 5.1 |
| larastan/larastan | 3.14 (level 6) | tailwindcss | 4 |

Horizon and Reverb are **not** installed (shared hosting).

## Next.js 16 notes (read `frontend/AGENTS.md` and `frontend/node_modules/next/dist/docs/` before changing frontend code)
- Middleware is called **Proxy** in Next 16, and is unavailable in a static export anyway.
- `cacheComponents` / `partialPrefetching` must stay **off**: "PPR cannot be enabled in export mode".
- Client components using `useSearchParams` need a `<Suspense>` boundary or the build fails.
- Typed `LayoutProps<"/">` / `PageProps` globals are available.

## Confirmed client decisions (2026-11-08)
- Page = Facebook page. Single company with several branches (no multi-tenancy).
- 30% advance is calculated on the total including delivery and tax.
- Urgent = fewer than 7 **working days** (exactly 7 is normal); urgent orders need **both** a Second Admin approval and an Operation Admin approval from two different people (Phase 03 instruction, supersedes the earlier shop-manager rule).
- Customers do not log in. Staff record all customer approvals with evidence.
- Sewing is a production stage.
- Balance is not required before dispatch; cash on delivery is allowed.
- Hosting is **shared hosting** (profile A in ARCHITECTURE.md §5).

## Project architecture
- Monorepo: `backend/` (Laravel API, modular monolith) and `frontend/` (Next.js App Router, TypeScript strict).
- Backend modules live in `backend/app/Modules/<Module>/` with `Actions/ Data/ Enums/ Events/ Http/{Controllers,Requests,Resources}/ Jobs/ Listeners/ Models/ Policies/ Queries/ routes.php`. Create a folder only when it has content.
- Modules: Identity, Organizations, Customers, Pricing, Orders, Payments, Invoices, Design, Garments, BulkProduction, Printing, Cutting, Sewing, QualityControl, GatePass, Delivery, Notifications, Documents, Audit (Reporting from Phase 13).
- A module with routes or policies has `<Module>ServiceProvider extends App\Support\Modules\ModuleServiceProvider`, registered in `bootstrap/providers.php`. Its `routes.php` is mounted under `/api/v1` with the `api` group.
- Shared kernel: `app/Support/` (Authorization, Http, Exceptions, Database, Enums, Modules).
- Table names: the client's "jobs" are `orders` (`order_no` = Job No.), because `jobs` is the Laravel queue table. Full mapping in DATABASE_DESIGN.md §3.
- Migrations live in `backend/database/migrations` (one ordered history).
- Frontend features live in `frontend/src/features/<domain>/` (`api.ts`, `queries.ts`, `schemas.ts`, `components/`).
- MySQL 8 (InnoDB). Cache, sessions, queues and locks use the **database** drivers on shared hosting, and Redis on a VPS. Code must never depend on a specific driver.
- Queues are processed by cron (`schedule:run` every minute). No job may run longer than about 45 s — chunk large work.
- Notifications are database-backed and the frontend polls. No WebSockets on shared hosting.
- Frontend is a **Next.js static export**: no middleware, server actions, API routes or dynamic segments. Record pages use `?id=`.
- Files go on a private folder outside `public_html` (or S3-compatible storage). Large uploads are chunked.

## Coding conventions
### Backend (PHP)
- `declare(strict_types=1);` in every file. PSR-12 via Pint. Larastan level 6 minimum, raised over time.
- Controllers are thin: authorize → validate (FormRequest) → call one Action → return a Resource.
- Business logic lives only in **Actions** (one public `handle()` / `__invoke()` per use case). Actions own their DB transaction.
- Models: explicit `$fillable`, casts for enums, money stays integer. No business logic in model events or observers.
- Status values are PHP backed enums stored as VARCHAR.
- Cross-module: call the other module's Actions/Queries or listen to its events. Never write another module's tables directly.
- No repository interfaces or extra layers without two real implementations.
- Events that trigger side effects are dispatched after commit.
- Protected routes use the `authenticated` middleware group. Every controller action calls `Gate::authorize()` (or a policy through a FormRequest). `RequireAuthorization` throws in tests when a route returns success without any Gate check. Exemptions (own-data routes only) use `->withoutMiddleware(RequireAuthorization::class)` and must be added to `RouteAuthorizationTest`.
- Policies extend `App\Support\Authorization\ScopedPolicy`. Branch-owned models use the `BranchScoped` trait (`$scopeByPage` or `$scopeByPageViaOrder`), and list queries call `->visibleTo($user)`.
- Domain errors: throw `App\Support\Exceptions\ApiException` (for example `ApiException::conflict('invalid_transition', '…')`). Never return ad-hoc error JSON.
- Lists: `ListQuery::perPage()`, `ListQuery::applySort($q, $request, SORTS, default)`, `ApiResponse::paginated()`.
- Audit: `app(AuditLogger::class)->record('module.action', $subject, ['before' => …, 'after' => …])` inside the same transaction.
- New models: add them to the morph map (`app/Support/Database/MorphMap.php`). `ModelIntegrityTest` fails otherwise.
- `Model::shouldBeStrict()` is on outside production: lazy loading, discarded attributes and missing attributes throw.
- Authorization order in every policy (`ScopedPolicy::decide()`): out of scope → 404, not assigned (for `*.view_assigned` users) → 404, missing permission → 403, wrong record state → 409 (`Response::denyWithStatus(409, …, code)`). Use the central policies in `app/Modules/*/Policies` from every new endpoint; list queries use `visibleTo()` or `accessibleTo($user, viewAll, viewAssigned, assigneeColumn)`.
- FormRequests that bind a record authorize in `authorize()` with `Gate::inspect(...)`, so unauthorized callers get 403/404 before any validation message.
- Administration never escalates privileges: `AccessGuard` (Identity) — only grant permissions/roles you hold, only assign branches/pages in your scope, only manage users whose permissions are a subset of yours, never administer your own account.
- Ending a user's sessions: `RevokeUserSessions` (increments `users.session_version`; `EnsureSessionIsValid` rejects older sessions). Driver-independent — never delete rows from `sessions` directly.
- Roles use `App\Modules\Identity\Models\Role` (guard always `web`, because `auth:sanctum` switches the default guard to `sanctum`). Role definitions: `Identity\Support\Roles`; permission catalogue: `Identity\Support\Permissions`.

### Frontend (TypeScript)
- `strict: true`, no `any` (use `unknown` and narrow). ESLint + Prettier.
- Server state through TanStack Query only. Forms with React Hook Form + Zod.
- All API calls go through `lib/api-client.ts` (credentials, XSRF header, error envelope).
- Money is displayed from integer minor units with `lib/format.ts`. Dates are shown in Asia/Colombo.
- Show or hide actions using the `abilities` returned by the API. This is never a security control.
- Authenticated pages fetch client-side. On a 401, redirect to `/login` client-side (no `middleware.ts` in a static export).

## Security rules (non-negotiable)
- Every API route needs auth plus a policy check. Record-level checks cover **permission + branch + state**. Other-branch records return 404.
- Check permissions, never role names.
- Validate every input with a FormRequest. Never use `$request->all()` or `$guarded = []`.
- No raw SQL with interpolated input. Sort and filter fields come from whitelists.
- No `dangerouslySetInnerHTML` (unless sanitised and approved). No `{!! !!}` with user data.
- Files go on a private disk, are type-checked by content, use UUID paths, and are downloaded through authorized short-lived signed URLs.
- Never commit `.env`, secrets, or real customer data. Never log passwords, tokens, or full personal data.
- Payment recorder and verifier must be different users.
- Audit every action listed in WORKFLOW_REQUIREMENTS.md §3.14.

## Database standards
- Money: `BIGINT` minor units, `_minor` suffix. Never float or double.
- UTC timestamps. Business dates as `DATE` interpreted in Asia/Colombo.
- FKs on every relation, `RESTRICT` by default. Transactional records are never deleted (cancel or void instead).
- Denormalised `branch_id` on directly listed transactional tables.
- `version` column for optimistic locking on orders, designs and garment lists.
- JSON columns only for the audit diff, notification payloads and framework tables.
- Never edit a migration that has run in a shared environment. Every new filter or sort needs a matching index.

## API standards
- Base path `/api/v1`. JSON only. Plural resource nouns. Workflow actions are explicit sub-resources: `POST /orders/{id}/transitions` with `{ "to": "design_review", "version": 4, "reason": null }`, `POST /payments/{id}/verify`.
- Success: Laravel Resources (`{ "data": …, "meta": …, "links": … }`), each with an `abilities` object on detail resources.
- Errors: `{ "message": string, "code": string, "errors"?: {field: string[]}, "request_id": string }`.
  - 401 unauthenticated · 403 forbidden · 404 not found / other branch · 409 invalid state or version conflict · 422 validation · 429 rate limit.
- Pagination `?page=&per_page=` (max 100). Filters `?filter[status]=`, sort `?sort=-created_at` (whitelisted).
- Money fields: `{ "amount_minor": 123450, "formatted": "LKR 1,234.50" }`. Dates: ISO 8601 UTC. `DATE` fields: `YYYY-MM-DD`.
- `Idempotency-Key` header supported on payment creation.
- Breaking changes need a new API version.

## Business invariants (must always hold — test them)
1. Totals, prices, advance, urgency and quantities are calculated on the backend only.
2. Unit prices are snapshotted on order items when the order is submitted.
3. An order cannot reach `confirmed` ("Approved Order") until verified payments ≥ `ceil(total × advance_percent / 100)` (`AdvanceRule`), where `total` includes delivery and tax, with `advance_percent ≥ 30` unless an audited exception exists. Verifying a payment and being eligible for production are separate conditions (`Order::meetsAdvanceRequirement()`, `isProductionEligible()`).
4. Urgent orders (fewer than 7 **working days** after the submission date up to and including `required_delivery_date`, Asia/Colombo, `UrgencyRule`) need the Second Admin AND the Operation Admin approval (two different users) before confirmation.
5. Status changes only through `TransitionOrder`, with a row lock, version check, history row and audit entry. Pre-approval states are derived by `EvaluateOrderRelease`; no endpoint accepts a status.
6. Design versions are immutable. Printing uses only the approved version.
7. Printing uses only a locked garment list. Changing the list or the approved design invalidates print preparation.
8. Production assignment quantities equal the ordered quantity exactly.
9. Issued invoices are immutable. Corrections are made with a credit note or void + reissue. Invoice numbers are sequential per page per year and never reused.
10. Gate passes are approved before checkout, and out/returned quantities are reconciled before closing.
11. Dispatch is allowed with a balance outstanding (COD). The COD amount equals the outstanding balance at dispatch. An order is completed only when the **verified** balance is 0 (COD collections verified by accounts) or a write-off is approved.
12. Users only see and change data in their assigned branches, unless they hold the global-access permission.
13. Customers never log in. Every customer approval (design, print sample) is recorded by staff against a specific version, with channel and evidence.

## Testing requirements
- Test helpers (`tests/TestCase.php`): `signedInAs()`, `asGuest()`, `userWith(perms, branches, pages)`, `userWithRole(role, branches, pages)` (seed `PermissionSeeder` first), `makeOrder()`, `makeOrderItemId()`. Order-intake fixtures (`tests/Support/intake.php`): `intakeWorld()` (today frozen at 2026-11-09 Colombo), `orderPayload()`, `createOrder()`, `recordPayment()`, `verifyPayment()`. Policy matrices: `tests/Feature/Authorization/WorkflowPolicyTest.php`.
- Numbering concurrency: `tests/Feature/Orders/JobNumberTest.php` spawns real PHP processes (`tests/Support/sequence-worker.php`) against a temporary SQLite file (`--group=concurrency`).
- Production seeding: `php artisan db:seed --class=PermissionSeeder` and `--class=CatalogSeeder` (order types, product categories, list fields and option sets, bulk meter / patch meter / collar meter fields and starter bulk checklists; idempotent; no sizes, fabrics or prices). Re-run both after deploying Phase 05 (new permissions `bulk.manage_external`, `bulk.assignment_override`, `bulk.correct_measurements`, `bulk.configure`) and Phase 06 (`printing.print_documents`, `printing.record_gate_pass`) and Phase 07 (`cutting.complete`, `cutting.configure`, `cutting.confirm_client`; `qc.inspect` now granted to Operation Admin and Cutting Staff, `cutting.view` + `cutting.confirm_client` to Page Owner; CatalogSeeder adds starter cutting checklist and quality check templates). Existing roles keep their permissions, so grant them in the role editor or seed with `RESET_ROLE_PERMISSIONS=true`.
- Cutting fixtures (`tests/Support/cutting.php`): `cuttingWorld()` (adds `cutter`, `otherCutter`), `cuttingReadyJob()`, `assignCutter()`, `recordCut()`, `cutChecklistItems()`, `completeCuttingChecklist()`, `passQualityChecks()`, `confirmCuttingChecklist()`, `completeCutting()`, `reportCuttingIssue()`, `moveIssue()`, `readyToCompleteCutting()`.
- Vitest on Windows: run it from a path with an UPPERCASE drive letter (`D:\React\…`, e.g. PowerShell). A lowercase `d:` cwd loads vitest twice and every file fails with "Vitest failed to find the current suite".
- Printing fixtures (`tests/Support/printing.php`): `completedInternalBulk()`, `completedExternalBulk()`, `printReadyJob()`, `sendForPrinting()`, `uploadSample()`, `approveSample()`, `rejectSample()`, `startPrinting()`, `completePrinting()`, `recordGatePass()`, `approvedSampleJob()`, `printVersion()`.
- Bulk fixtures (`tests/Support/bulk.php`): `bulkWorld()` (adds a supplier), `readyBulkJob()`, `assignInternal()`, `assignExternal()`, `enterMeasurements()`, `completeChecklist()`, `confirmChecklistWithClient()`, `submitBulk()`, `approveBulk()`, `submitFinalBulk()`, `approvedInternalBulk()`, `jobVersion()`.
- Design/list/pricing fixtures (`tests/Support/design.php`): `designWorld()` (configured designer, bulk designer, fabric, sizes S/M/L, page A jersey prices), `approvedOrder()`, `submitJobDetails()`, `submitVersion()`, `completeDesign()`, `jerseyRows()`, `fillList()`, `confirmList()`, `priceList()`.
- Gate abilities must never share a name with a Spatie permission (`Gate::define('settings.manage')` recursed forever): use names like `workflow-settings.manage`.
- Backend: Pest feature tests for every endpoint — happy path, 422, 401, 403, cross-branch 404, invalid-state 409. Unit tests for guards, calculators and sequences. A concurrency test for numbering. A route-authorization coverage test.
- Lazy loading is disabled in tests (an N+1 throws). Assert query counts on key list endpoints.
- Frontend: Vitest + Testing Library for forms and components. Playwright E2E for the order lifecycle, extended each phase.
- CI must be green (tests, Larastan, Pint, ESLint, `tsc --noEmit`, `composer audit`, `npm audit`) before merge.
- Test data comes from factories. Never use real customer data.

## Performance requirements
- No N+1. Explicit eager loading and column selection in Query classes.
- Paginate every list (default 25, max 100).
- Queue PDFs, notifications, imports, exports, thumbnails and scans.
- Cache reference data and permissions in the cache store with explicit invalidation. Never cache data used for financial writes.
- Keep transactions short, with no external I/O inside them.
- Targets: API p95 reads < 300 ms, writes < 500 ms.

## Implementation workflow
1. Read this file and the relevant sections of the reference documents.
2. Confirm the phase scope and that its blocking questions are answered. If a business rule is ambiguous, **ask — do not guess**.
3. Plan: list migrations, actions, endpoints, policies, tests and UI screens. Get user approval for non-trivial plans.
4. Implement backend first (migration → model → action → policy → request → resource → controller → routes → tests), then the frontend.
5. Run the full test suite and static analysis. Report results honestly, including failures.
6. Update the docs when a decision changes (DATABASE_DESIGN.md, WORKFLOW_REQUIREMENTS.md, this file).
7. Never delete or rewrite existing functionality without first understanding its dependencies and purpose.
8. Commit only when the user asks. Use conventional commit messages (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`).

## Commands
| Task | Command (from `backend/` or `frontend/`) |
|---|---|
| Backend tests | `../.tools/php.sh vendor/bin/pest` |
| Style / static analysis | `../.tools/php.sh vendor/bin/pint` · `../.tools/php.sh vendor/bin/phpstan analyse --debug` |
| Migrate / seed (dev) | `../.tools/php.sh artisan migrate` · `../.tools/php.sh artisan db:seed` (admin password printed once, or set `SEED_ADMIN_PASSWORD`) |
| Production config check | `php artisan app:security-check` |
| API server | `../.tools/php.sh artisan serve --port=8000` |
| Dev (API + frontend) | `npm run dev` in `frontend/` starts `artisan serve` on :8000 and `next dev` together (`scripts/dev.mjs`; reuses a running API; PHP from `PHP_BIN`, else `.tools/php.sh`, else `php`). `npm run dev:frontend` = Next.js only |
| Frontend | `npm test` · `npm run typecheck` · `npm run lint` · `npm run build` (writes `out/`) |

## Environment notes
- Local Windows machine: PHP 8.2 (XAMPP), no global Composer. `.tools/php.sh` wraps XAMPP PHP with `intl`/`sodium` and a CA bundle exported from the Windows store. Avast HTTPS scanning breaks PHP's default TLS verification, which is why the bundle exists. `.tools/composer.sh` runs Composer. `.tools/` is git-ignored and machine-specific.
- The local XAMPP **MariaDB data directory is corrupt** (InnoDB "log sequence number in the future", privilege tables unreadable). Do not start it or attempt repair without the user's explicit approval and a backup of `C:\xampp\mysql\data`. Local dev uses SQLite (`backend/database/database.sqlite`).
- Disk I/O is slow (the permission seeder takes about 5 min on SQLite, and phpstan's parallel mode hangs, so use `--debug`). Run long commands in the background.
- Preferred dev environment remains WSL2 or Docker with PHP 8.3+ and MySQL 8.
- Production is shared hosting. Before relying on a server feature (PHP extension, cron frequency, triggers, upload limit), check that the host supports it.
- Do not touch the sibling projects in `D:\React`.
