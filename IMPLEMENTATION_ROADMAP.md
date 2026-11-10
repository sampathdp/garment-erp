# Implementation Roadmap

Each phase is small enough to review, ends with passing tests and a security checklist, and does not start until the previous phase is accepted. **Phase 01 does not begin until the client answers the blocking questions listed below.**

## Phase 00 — Discovery and documentation (this phase)
Audit, architecture, database design, standards, workflow rules, CLAUDE.md.
**Exit:** client reviews the documents and answers the questions in WORKFLOW_REQUIREMENTS.md §6.

## Phase 01 — Foundation
**Blocked by:** Q24 (shared hosting details). The hosting must be checked against ARCHITECTURE.md §5.2 **before** scaffolding, because it decides the PHP version, MySQL vs MariaDB and the deploy method.
- Set up the local environment: WSL2/Docker, PHP 8.3+, Composer, MySQL 8, Mailpit. Use database drivers to match shared hosting.
- `git init`, scaffold the Laravel API and the Next.js app (TypeScript strict, `output: 'export'`), and pin versions in CLAUDE.md.
- Deploy a "hello world" to the real shared host: API under the subdomain with Laravel outside `public_html`, the static frontend, cron running `schedule:run`, and a test queued job processed by cron. Prove the cookie login works across the subdomains.
- Module loader (service provider per module), API versioning (`/api/v1`), error envelope, request ID middleware, security headers, CORS, health endpoint.
- Tooling: Pest, Larastan, Pint, ESLint, Prettier, Vitest, Playwright; GitHub Actions CI with MySQL (database drivers), plus one Redis job for profile B.

**Exit:** CI green; `/api/v1/health` reachable from the frontend with CORS and cookies working, **on the real shared host**.

**Status (2026-11-09): implemented locally. Two exit items are open.**
- Done: Laravel 12 modular backend (19 modules), full schema (24 migrations, 75 tables) with models and relationships, Sanctum SPA auth (login/logout/me, CSRF, session rotation, absolute lifetime, inactive-account block, rate limits), Spatie RBAC with the permission catalogue and role bundles, branch/page scoping, object-level policies, the `RequireAuthorization` safety net, error envelope, security headers, request size limits, request IDs, audit log, file validation rule, cache/queue/scheduler for shared hosting, slow-query logging, structured JSON logs, `app:security-check`. Next.js 16 static-export frontend with API client, auth, permission-aware navigation, DataTable, ConfirmDialog, toasts, notification polling, and loading/empty/error states.
- Verified: 54 Pest tests, Larastan level 6 clean, Pint, 18 Vitest tests, `tsc`, ESLint, `next build`, and a manual curl smoke test of CSRF → login → scoped list.
- **Open:** (1) deploy to the real shared host — blocked by Q24; (2) CI has never run, because the repository has no remote yet (the MySQL 8.4 job is the only check of CHECK constraints and triggers); (3) Playwright E2E is moved to Phase 02; (4) the Laravel 13 upgrade needs PHP 8.3+.

## Phase 02 — Identity, access, organization, audit
**Blocked by:** Q17 (languages — decides whether i18n is built in from the start). Q1 (page-limited staff) affects the `page_user` table. Q13 is answered: single company.
- Already delivered in Phase 01: login/logout/me, rate limits, inactive-user block, roles/permissions and bundles, branch/page scoping, base policy, audit infrastructure.
- Remaining: password reset, user/role/branch/page management APIs and UI, admin-managed holiday calendar, Playwright E2E, the Laravel 13 upgrade (after PHP 8.3+), and a constant-time login for unknown emails.
- Audit log infrastructure plus the route-authorization CI test.

**Exit:** authorization test suite pattern established (401/403/404 cross-branch).

**Status (2026-11-09): implemented locally.**
- Done: forgot/reset password (hashed single-use 60-minute tokens, queued mail, no enumeration), change password, profile, driver-independent session revocation (`users.session_version`), exponential login lockout, constant-time login (framework Timebox), user management (create with setup link, edit, activate/deactivate, roles, branch/page assignments incl. page owners, admin-sent reset links, activity feed), role management (nine initial roles, custom roles, permission matrix, locked Super Admin), branch and page management (contacts, address, pricing defaults, owners/members), privilege-escalation guard, central workflow policies (orders, design tasks, garment lists, bulk jobs, printing, cutting, payments, production issues) with scope/assignment/permission/state checks, frontend screens for all of the above.
- Open: Q25–Q28; Playwright E2E (still not installed); Laravel 13 (needs PHP 8.3+); admin holiday calendar screen (moved to Phase 04 with the working-day calculator); CI has still never run (no remote).

## Phase 03 (as delivered) — Customers, order intake, payment verification, approval

The user's Phase 03 brief replaced the original Phase 03 below. Delivered (2026-11-09):
- Customers CRUD (page/branch association, WhatsApp, E.164 validation, page-scoped duplicate protection without merging, search, soft delete without orders).
- Configurable order types and product categories (`CatalogSeeder`), order form (customer information + job specifications + agreed price), drafts and submission, edits before approval with optimistic locking.
- Server-generated job numbers `{BRANCH}-{PAGE}-{YY}-{NNNNN}` (increment-first sequences, real multi-process concurrency test).
- Seven-day rule on working days (exactly 7 = normal), dual urgent approval (Second Admin + Operation Admin, two people), re-evaluation on due-date change.
- Statuses Draft / Pending Payment Verification / Payment Rejected / Urgent Approval Pending / Urgent Approval Rejected / Approved Order, applied only by `TransitionOrder`.
- Advance payments (multiple, evidence on the private disk, signed downloads, Idempotency-Key), Second Admin confirm/reject with reason, verification history, separation of duties, 30% advance on the backend, production eligibility kept separate from verification.
- Approved Orders, drafts/pending, urgent approval and payment verification screens; queued in-app notifications.
- Moved to later phases: secure generic document upload API (only payment evidence exists), price lists and the price calculation service, holiday calendar screen, Playwright E2E.

## Phase 04 (as delivered) — Job details, design, name/number lists, pricing, invoices, bulk eligibility

Delivered (2026-11-10) from the user's Phase 04 brief (it combines the original Phases 05–07 and part of 08):
job details after payment approval; design tasks with a configurable responsible designer and a due-date-ordered design plan; immutable design versions (preview/PDF/CDR, allow-listed links never fetched); correction rounds with a configurable limit and audited override; Page-Owner-only final approval and mandatory final files; configurable list fields per category with option/size master data, bulk entry, CSV import/export, review, confirmation lock and administrative correction; rule-based page pricing with effective dates and frozen used lists; immutable invoice snapshots, authorised discounts, optional tax, advance recalculation and remaining balance; one-per-order bulk jobs with notifications.
Still open: invoice PDFs, credit notes, chunked uploads for very large design files, XLSX import, holiday screen, Playwright E2E, client pricing tables / sizes / fabrics (data entry), Q8/Q9/Q18.

## Phase 05 (as delivered) — Bulk production management

Delivered (2026-11-10) from the user's Phase 05 brief (part of the original Phase 08): bulk dashboards for the Operation Admin and Bulk Designers; whole-order internal/external assignment with history and restricted external edits; configurable bulk measurements with validation, change history and audited post-completion correction; per-job checklist instances from versioned templates with client confirmation; Page Owner bulk review with correction rounds and immutable versions; list locking against the approved bulk; internal final submission (final files + WeTransfer link) and external completion with the same mandatory data; Job Card read model; suppliers and bulk setup screen; notifications for every step.
Still open: quantity splits across several units/suppliers (`bulk_assignments`, Q14), per-size measurement points with tolerances (`size_specs`), client confirmation of the collar-measurement categories and checklist contents, Playwright E2E.

## Phase 03 (original plan) — Documents, customers, pricing
- Secure document upload and download (private disk, presigned uploads, signed URLs, MIME checks).
- Customers CRUD with search.
- Product types, sizes, options, and price lists per page with a price calculation service.

## Phase 04 — Orders core
**Blocked by:** Q2 (exceptions, top-up), Q14, Q21 (sewing position), Q22 (working week and holiday counting).
- Orders and items with price snapshots, backend totals, `order_no` sequence.
- State machine engine (enum, `TransitionOrder`, guards, history, optimistic locking).
- Working calendar (weekdays, company and branch holidays) and the working-day calculator, with thorough unit tests around holidays and year boundaries.
- Urgent rule (< 7 working days) and shop-manager urgent approval.
- Database notifications infrastructure.

## Phase 05 — Payments and invoices
**Blocked by:** Q9, Q23 (COD collection model affects payment methods).
- Payment recording with slip upload, idempotency, verification/rejection with separation of duties.
- Advance rule and automatic confirmation.
- Invoice numbering per page, proforma/advance/final invoices, credit notes, void, queued PDFs.

## Phase 06 — Design management
**Blocked by:** Q7, Q18.
- Designs, immutable versions, files (chunked uploads on shared hosting), staff-recorded reviews with channel and screenshot evidence, correction items, approval.

## Phase 07 — Garment lists
**Blocked by:** Q8.
- Entries editor (virtualised grid), CSV/XLSX import with per-row errors, export with injection protection, versions, locking.

## Phase 08 — Bulk assignment and production
**Blocked by:** Q6, Q14, Q19.
- Production units, suppliers, assignments (internal/external), quantity coverage rule.
- Measurement points, size specs, bulk measurements, checklist templates and runs.

## Phase 09 — Gate passes and printing
**Blocked by:** Q11.
- Gate pass module (numbering, approve, check out, return, close).
- Print preparation, samples and approval, physical printing, invalidation on design or list change.

## Phase 10 — Cutting, sewing and quality control
**Blocked by:** Q10, Q21.
- Cutting batches, sewing batches (internal/external with gate pass), QC inspections, defects, rework loops.

## Phase 11 — Delivery, COD and completion
**Blocked by:** Q23.
- Deliveries, dispatch gate pass, COD amount at dispatch, COD collection and courier remittance recording, proof of delivery, completion rule, cancellation and refund flows.

## Phase 12 — Notifications
**Blocked by:** Q12.
- Full event-to-recipient matrix, preferences, notification polling, email/SMS providers, scheduled risk checks (overdue gate passes, delivery dates at risk, missing holiday calendar for next year). Reverb only if the hosting moves to a VPS.

## Phase 13 — Reporting and dashboards
**Blocked by:** Q20.
- Branch and page dashboards, sales by page, outstanding balances, production throughput, supplier performance, exports.

## Phase 14 — Data migration
**Blocked by:** Q16. Import scripts for existing customers and open orders, with a dry-run mode.

## Phase 15 — Hardening and go-live
- 2FA for privileged roles, full security review, dependency audit, k6 load test on the shared host, backup/restore drill (offsite DB and file backups — host backups alone are not enough), staging UAT with the client, production deployment runbook, monitoring and alerts (uptime check, failed-job email, error tracking).

## Testing required in every phase
- Backend feature tests for every endpoint: happy path, validation, 401, 403, cross-branch 404, invalid state 409.
- Unit tests for guards, calculators and number sequences (including a concurrency test for sequences).
- Frontend component tests for forms; a Playwright E2E test extended phase by phase to cover the full order lifecycle.
