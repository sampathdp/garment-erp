# Known Limitations

What the system does not do yet, or does with a known weakness, as of Phase 09 (2026-11-13). Each item says why it matters and what would remove it. "Blocker" = must be resolved before real customer data goes into production.

## 1. Production blockers

| # | Limitation | Impact | Resolution |
|---|---|---|---|
| B1 | **No backups are configured** (BACKUP_AND_RECOVERY.md §6). | Losing the hosting account or a bad migration loses every order, payment and file. | Schedule the backup script, the encrypted off-site copy and one restore drill. |
| B2 | **Deploy over FTPS without certificate verification** (`ssl:verify-certificate no`). | The FTP password and the uploaded code can be intercepted or replaced in transit (supply-chain risk). | Verify the host certificate or move to SFTP with a deploy key (DEPLOYMENT_GUIDE.md §9). |
| B3 | **No two-factor authentication** for Super Admin, Page Owners and Second Admins (SECURITY_STANDARDS.md §1, planned Phase 15). | One phished password gives full or financial access. | TOTP 2FA for privileged roles. |
| B4 | **Local and CI databases differ**: development runs on MariaDB 10.4 (end of life), CI on MySQL 8.4, production is MySQL (DEPLOYMENT_GUIDE says MariaDB 10.6+ also works). The Phase 09 tests ran on MariaDB 10.4 locally. | SQL behaviour (CHECK constraints, optimizer plans) can differ from production. | Run CI (MySQL 8.4) on this branch before deploying, and check the host's database version. |
| B5 | **Open client questions** Q22 (working days and holidays), Q25–Q29 (approvers, scopes, Job Card print stage, printed quantity). Defaults are assumptions. | The seven-day rule and approvals may not match how the client works. | Client answers (WORKFLOW_REQUIREMENTS.md §6.2). |

## 2. Security

| Limitation | Notes |
|---|---|
| Frontend CSP allows `'unsafe-inline'` scripts | A static export cannot use nonces; build-time hashes planned (Phase 15). React escaping, no `dangerouslySetInnerHTML` and the allow-listed links limit the XSS surface. |
| Approval bypass for company-wide page scope | Users holding `scope.all_pages` plus an approval permission skip the Page-Owner check (by design, Super Admin). Do not give that combination to custom roles. |
| Role editing affects more privileged users | A role administrator can remove a permission they hold from a role that a more privileged user also has. Audited; restrict `roles.manage`. |
| No malware scanning | Shared hosting has no ClamAV; uploads rely on the content-type allow-list, private storage and download-only serving for sources/SVG. |
| No error-tracking or alerting service | Errors are in the JSON log only; uptime monitoring on `/api/v1/health` is the only alert. Add Sentry (or similar) and alerts on failed logins / 5xx (SECURITY_STANDARDS.md §9). |
| Dev-only npm advisories | 8 moderate (`minimatch` ReDoS in the ESLint toolchain); production dependencies have none. |
| No external penetration test | Recommended before go-live (SECURITY_STANDARDS.md §13). |

## 3. Testing

| Limitation | Notes |
|---|---|
| **No browser end-to-end tests (Playwright)** | Never installed. The full lifecycle is tested at API level (`tests/Feature/Lifecycle`, internal and external bulk), which proves the backend rules but not the screens. Install Playwright in CI (browser downloads) and port the lifecycle test to the UI. |
| No load test on the real host | Targets (p95 reads < 300 ms) were checked with ANALYZE on a volume copy, not under concurrent load on shared hosting (k6, Phase 15). |
| Frontend tests are unit/component level | 83 Vitest tests; no screen-level tests for most workflow screens. |

## 4. Performance

| Limitation | Notes |
|---|---|
| Free-text order search scans the table | `filter[search]` matches `order_no LIKE '%…%'` or the customer name/phone: a leading-wildcard search reads every order (0.3–7 s cold at 200k orders on the local disk-bound server). Fine at today's volume; at scale add a search column/index (e.g. trailing digits of the job number, a FULLTEXT/ngram index on customer names) or require a minimum term length. |
| List totals use `COUNT(*)` | Every paginated list counts its rows (≈ 60–90 ms at 200k orders). Switch long lists to simple (next/previous) pagination if it becomes noticeable. |
| Other page-via-order tables keep the subquery | `bulk_jobs`, `print_jobs`, `cutting_jobs`, `sewing_jobs`, `deliveries`, `production_issues` are scoped to pages through `orders`; only `payments` (many rows per order) was denormalised after measuring. Re-measure when those tables grow. |
| Garment lists render all rows | Up to 2,000 rows per list are rendered without virtualisation (search is deferred, bulk-paste parsing memoised). |
| Frontend JavaScript weight | 200–260 KB gzip per page (React, Next runtime, TanStack Query, React Hook Form, Zod). Acceptable for an internal tool on desktop; not tuned for slow mobile connections. |

## 5. Features not built yet

Write-offs (a remaining balance always blocks completion), refunds and credit notes, invoice PDFs, the full gate pass workflow (approval, check-out, returns), quantity splits across several producers (Q14), size specs with tolerances, rework routing after QC (Q10), internal vs external sewing (Q21), courier COD remittances (Q23), e-mail/SMS/WhatsApp notifications (Q12), the holiday calendar screen, price-list driven unit prices on the order form, chunked uploads for very large design files, XLSX import, data migration (Q16), the Laravel 13 upgrade (needs PHP 8.3+).

## 6. Hosting (shared, profile A)

No Redis, Horizon, Reverb or long-running workers: queued work and notifications are processed by the per-minute cron (up to ~1 minute delay; `/api/v1/health` shows `scheduler: stale` if cron stops). No binary logs, so no point-in-time recovery. Upload size is limited by the host's PHP settings. Moving to a small VPS (profile B) removes these limits without code changes (ARCHITECTURE.md §5).
