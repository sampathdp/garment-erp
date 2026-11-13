# Testing Guide

How the test suites are organised, how to run them locally and in CI, and what each kind of test must cover. CLAUDE.md "Testing requirements" lists the fixture helpers; this guide explains how to use them.

## 1. Suites

| Suite | Location | Runner | What it covers |
|---|---|---|---|
| Backend unit | `backend/tests/Unit` | Pest | Pure rules: status machine, advance and urgency calculators |
| Backend feature (API) | `backend/tests/Feature/<Module>` | Pest + MySQL | Every endpoint through HTTP: happy path, 422, 401, 403, cross-branch/page 404, invalid-state 409 |
| Authorization | `tests/Feature/Authorization` | Pest | Route coverage (every route authenticated and policy-checked), the policy matrix per role, branch/page scoping |
| Security regression | `tests/Feature/Security` | Pest | HTTP foundation (headers, request id, CORS, error envelope, body size, health) and `BypassAttemptsTest`: hand-crafted requests that try to get around a rule (other page/branch, self-approval, locked lists, financial fields, skipped stages, private files, disguised uploads) |
| Workflow lifecycle (API end-to-end) | `tests/Feature/Lifecycle/FullJobLifecycleTest.php` | Pest | One job from order intake to completion through the public API, for the internal and the external bulk path, asserting the order status after every step, the invoice snapshot and the full status history |
| Concurrency | `tests/Feature/Concurrency`, `tests/Feature/Orders/JobNumberTest.php` | Pest `--group=concurrency` | Real OS processes hitting the same row at the same instant (see §4) |
| Database integrity | `tests/Feature/Database` | Pest | Every model in the morph map, fillable/casts rules |
| Frontend unit/component | `frontend/src/**/*.test.ts(x)` | Vitest + Testing Library | Forms, API client, formatting, navigation helpers (`lib/safe-path`) |
| Browser end-to-end | — | Playwright (not installed) | Not available yet (KNOWN_LIMITATIONS.md) |

## 2. Running the tests

Backend (from `backend/`; needs MySQL 8 or MariaDB with an empty `garment_erp_test` database, see `phpunit.xml`):

```sh
../.tools/php.sh vendor/bin/pest                          # everything, incl. concurrency (~13 min on the local disk)
../.tools/php.sh vendor/bin/pest --exclude-group=concurrency
../.tools/php.sh vendor/bin/pest --group=concurrency      # alone: these tests commit and then empty every table
../.tools/php.sh vendor/bin/pest tests/Feature/Lifecycle
../.tools/php.sh vendor/bin/pint --test
../.tools/php.sh vendor/bin/phpstan analyse --debug       # --debug: parallel workers hang on this machine
```

Frontend (from `D:\React\garment-erp\frontend` in PowerShell — an uppercase drive letter; a lowercase `d:` cwd loads Vitest twice and every file fails):

```powershell
npm test; npm run typecheck; npm run lint; npm run build
```

CI (`.github/workflows/ci.yml`, MySQL 8.4): `composer audit`, Pint, Larastan, the full Pest suite, `app:security-check --env-only`; `npm ci`, `npm audit --omit=dev --audit-level=high`, lint, typecheck, Vitest, build. Deploy runs only after CI passes on `master`.

## 3. Writing backend tests

- Build the world with the fixture chain, each level adding the next stage: `intakeWorld` → `designWorld` → `bulkWorld` → `cuttingWorld` → `deliveryWorld`. "Today" is frozen at 2026-11-09 Asia/Colombo.
- Drive state through the API helpers (`createOrder`, `recordPayment`, `approvedOrder`, `readyBulkJob`, `completedInternalBulk`, `printReadyJob`, `cuttingReadyJob`, `deliveryReady`, `deliveredAndSettled` …), never by writing status columns: a test that sets `orders.status` directly can pass while the real workflow is broken.
- Sign in with `signedInAs($user)`; for permission edge cases build users with `userWith([...permissions], branches, pages)`.
- Every new endpoint needs: 401 (guest), 403 (missing permission), 404 (other branch **and** other page), 409 (wrong state / stale version), 422 (validation), and the happy path. Add a bypass case to `BypassAttemptsTest` when the endpoint guards a business rule.
- Lazy loading throws in tests (`Model::shouldBeStrict()`); list endpoints assert their query count.
- Never weaken an assertion to make a test pass. A failing test is either a bug in the code or a wrong expectation that must be justified in the commit.

## 4. Concurrency tests

`tests/Support/race-worker.php` boots the application in a child process, waits for a shared start time and runs one Action; `WorkflowRaceTest` starts several workers per scenario. The fixtures are built inside the normal test transaction and then **committed** (`commitFixtures()`), because the workers use their own connections; `truncateAll()` empties every table in a `finally` block so later tests start from an empty database. Consequences:

- run the group alone or as part of the full sequential run, never in parallel with another run on the same database;
- if a run is killed in the middle, empty the test database (`php artisan migrate:fresh --env=testing`) before the next run.

Each scenario asserts that exactly one request wins, that the others get a clean 409 (never a deadlock, duplicate-key error or 500) and that no duplicate rows exist afterwards.

## 5. Performance checks

`DATABASE_DESIGN.md §7a` records the EXPLAIN/ANALYZE audit on a volume copy (200k orders, 300k payments, 500k notifications). To repeat it: create a separate database, `DB_DATABASE=<name> php artisan migrate --force`, load synthetic rows with SQL (MariaDB's `seq_1_to_N` tables or a recursive CTE on MySQL 8), `ANALYZE TABLE`, then run `ANALYZE FORMAT=JSON` (MariaDB) or `EXPLAIN ANALYZE` (MySQL 8) on the list queries. Never point this at the test or production database. Any new filter or sort needs this check (DATABASE_DESIGN.md §7).

## 6. Results of the Phase 09 run (2026-11-13)

Baseline before Phase 09: 329 backend tests passed (concurrency group excluded), 0 failed; 80 frontend tests passed.

After Phase 09: 353 backend tests. Full run (all groups, 869 s, local MariaDB 10.4): 350 passed, 3 failed — `WorkflowPolicyTest` built in-memory payments without the new `page_id`; the fixtures were corrected and `tests/Feature/Authorization` re-run 29/29 green. Concurrency group 2/2. Frontend: 83 Vitest tests passed, typecheck and build clean, ESLint 0 errors (86 pre-existing unused-import warnings). Playwright: not available.
