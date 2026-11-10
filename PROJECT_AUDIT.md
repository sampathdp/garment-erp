# Project Audit — Garment Manufacturing & Production Management System

Audit date: 2026-11-08
Project root: `D:\React\garment-erp`
Phase: 00 (discovery and documentation only — no application code)

## 1. Summary

This is a **greenfield project**. No code exists for this system anywhere in the workspace, so the audit covers the workspace, the local toolchain, and the requirements rather than an existing codebase.

| Area | Finding |
|---|---|
| Existing Next.js application | None |
| Existing Laravel application | None |
| Database migrations / schema | None |
| API endpoints | None |
| Authentication / authorization | None |
| UI components | None |
| Business logic | None |
| Integrations | None |
| Tests | None |
| Build / deployment config | None |
| Version control | `garment-erp` is not yet a git repository |

**Existing functionality that must be preserved:** none inside this project. The sibling folders in `D:\React` are separate, unrelated client projects. This project must not modify, import from, or share configuration with them.

## 2. How the audit was performed

1. Listed every top-level folder in `D:\React` (48 projects) and checked each one for Laravel (`artisan`) and Next.js (`next.config.*`) markers.
2. Searched folder names for garment-related terms (garment, cloth, apparel, textile, jersey, sport, print, uniform, stitch, tailor). No matches.
3. Searched source files (`*.php`, `*.ts`, `*.tsx`, `*.md`) for "garment" and "gate pass". No matches; the search was time-limited on this large workspace, so it is strong but not exhaustive evidence.
4. Checked other likely locations on drive D (`D:\Laravel`, `D:\PHP`, `D:\APP`, `D:\CodePlay Studio`). They contain gym, lab, payroll, tyre-shop and generic ERP projects only.
5. The client confirmed this is a new project with no existing base to reuse.

## 3. Local development environment (verified)

| Tool | Found | Required for this project | Gap |
|---|---|---|---|
| PHP | 8.2.12 (XAMPP, ZTS, Windows) | 8.3+ (current Laravel major); 8.2 only if pinned to Laravel 12 | **Upgrade PHP** |
| PHP extensions | bcmath, fileinfo, gd, mbstring, openssl, pdo_mysql, zip | also `intl`, `sodium`, `pcntl` (Linux only, for Horizon) | **Enable intl and sodium** |
| Composer | Not on PATH | Required | **Install** |
| Node.js / npm | 22.23.4 / 10.9.9 | Node 20.9+ for current Next.js | OK |
| Database | MariaDB 10.4.32 (XAMPP) | MySQL 8.0/8.4 LTS (production target) | **Mismatch, and MariaDB 10.4 is end-of-life** |
| Redis | Not installed | Not needed for shared hosting (database drivers); optional locally for testing profile B | — |
| Docker | Not installed | Optional | — |
| Git | 2.56 | Required | OK |

Recommendation: develop under **WSL2 (Ubuntu)** with PHP 8.3+ and MySQL 8, or install Docker Desktop and use Laravel Sail. Shared hosting runs Linux, so this matches production more closely than XAMPP on Windows. Running against MariaDB 10.4 locally risks SQL and migration behavior that differs from production MySQL.

## 4. Security findings in the workspace (outside this project)

These are not part of this project but were found during the audit and are serious:

- `D:\React\Note.txt` contains an **AWS access key ID and secret access key in plain text**, a JWT secret (`Admin123`) and API Gateway URLs. If the key is still active, deactivate it and create a new one in AWS IAM, then delete the file. Replace the JWT secret with a long random value.
- Several sibling projects keep committed `.env` files. This project must never commit `.env` (see SECURITY_STANDARDS.md).

## 5. Requirements review

The client's workflow list was reviewed for completeness and consistency. Full detail is in WORKFLOW_REQUIREMENTS.md.

### 5.1 Incomplete or missing business requirements

| # | Gap |
|---|---|
| G1 | **Answered: sewing is a stage** (exact position still to confirm, Q21). Original gap: **sewing/stitching is not mentioned.** The flow goes from cutting and QC straight to delivery. In garment production, cut panels are normally sewn. It is unclear whether sewing is tracked, outsourced, or covered by "bulk production". |
| G2 | The meaning of **"Pages"** is not defined. Assumed: sales channels or brands (for example Facebook pages), each with its own pricing and invoice identity. |
| G3 | ~~Who approves designs and print samples~~ — **answered:** customers do not log in; staff record approvals. |
| G4 | The **base for the 30% advance** is not defined (with or without delivery and tax), nor what happens when the order total changes after the advance is paid. |
| G5 | The **seven-day urgent rule** does not say calendar or working days, the start point, the approver, or whether there is a surcharge. |
| G6 | **Balance payment timing** is not defined (before dispatch, cash on delivery, or credit terms). |
| G7 | **QC failure handling** (reprint, recut, repair, scrap) and who bears the cost are not defined. |
| G8 | **Gate pass types** are not defined (to an external printer or producer, returns, delivery). |
| G9 | **External supplier** tracking scope is not defined (whether suppliers log in, and whether supplier payables are tracked). |
| G10 | **Notification channels** are not defined (in-app, email, SMS, WhatsApp). |
| G11 | **Tax** (VAT/SSCL) registration and invoice tax lines are not defined. |
| G12 | **Data migration** from current spreadsheets or other systems is not defined. |
| G13 | **Refunds, cancellations and credit notes** are not covered. |
| G14 | **Reporting** needs are not listed (sales by page, production throughput, outstanding balances, supplier performance). |

### 5.2 Conflicting or tension points in the technical brief

| # | Tension | Resolution proposed |
|---|---|---|
| C1 | "DECIMAL **or** integer minor units" | Use **integer minor units (BIGINT)** everywhere. Recorded in DATABASE_DESIGN.md. |
| C2 | "Avoid JSON columns" vs flexible checklists and measurements | Model checklists and measurements relationally (template, items, results). JSON is allowed only for the audit diff, notification payloads and framework tables. |
| C3 | "UTC internally" vs the seven-day rule | Store UTC, but evaluate date-based business rules on **Asia/Colombo calendar dates** (`required_delivery_date` is a `DATE`). |
| C4 | Reverb "if supported by deployment" | The client chose shared hosting, which cannot run Reverb. Notifications are database-backed with polling. Reverb can be added later on a VPS. |
| C5 | Redis for cache, queues and locks vs **shared hosting** (client decision) | Database drivers for cache, sessions, queues and locks; cron-driven queue processing; Redis/Horizon only on a future VPS. See ARCHITECTURE.md §5. |
| C6 | Sanctum SPA cookie auth vs Next.js server-side rendering, plus shared hosting without Node | Next.js static export. All authenticated data is fetched client-side, with the browser calling the API directly using cookies. |
| C8 | "Seven-day" rule vs working days (client answer) | A working-day calendar (weekdays + company and branch holidays) maintained by managers. |
| C7 | MySQL target vs local MariaDB 10.4 | Standardise on MySQL 8.4 LTS for dev, CI and production. |

## 6. Critical risks

| Risk | Impact | Mitigation |
|---|---|---|
| Workflow order ambiguity (G1, G3, G7) | Wrong state machine, expensive rework | Get client answers before Phase 04 (Orders state machine) |
| Scope size (18 modules) | Late delivery | Strict phased roadmap; each phase shippable and tested |
| Concurrent state transitions (two users approving or assigning at once) | Corrupt workflow and financial state | DB transactions + `lockForUpdate` + `version` column + cache locks for long operations |
| Invoice and gate-pass numbering under concurrency | Duplicate or gapped numbers | Per-scope sequence table locked inside a transaction |
| Cross-branch data leakage / IDOR | Data breach | Policy on every endpoint, branch-scoped queries, negative authorization tests per endpoint |
| Large design files (AI/PSD/PDF) | Upload failures, storage cost, malware | Private object storage, direct-to-storage presigned uploads, size and type allow-list, optional ClamAV |
| Local environment mismatch | "Works on my machine" bugs | WSL2/Docker, CI on MySQL 8, early deploy to the real shared host in Phase 01 |
| **Shared hosting** (client decision) | No Redis, Horizon, Reverb or ClamAV; queued work up to about 1 minute late; CPU and upload limits; per-table DB privileges unlikely | Database drivers + cron queue, static frontend, chunked uploads, offsite backups; prove the host in Phase 01 (SSH, per-minute cron, PHP 8.3+, MySQL 8). Recommend a small VPS if any requirement fails |
| Holiday calendar not maintained | Urgent rule wrong | Calendar admin screen + yearly reminder notification |
| COD cash not reconciled | Orders stuck or revenue lost | COD status on deliveries, accounts verification, outstanding-COD report |
| Credentials in workspace (`Note.txt`) | Account compromise | Rotate immediately (owner action) |

## 7. Code to preserve

None inside `garment-erp`. Sibling projects in `D:\React` are out of scope and must not be touched.

## 8. Phase 01 findings (2026-11-09)

| Finding | Impact | Action taken |
|---|---|---|
| Local XAMPP MariaDB 10.4 **will not start**: "log sequence number … is in the future" and "Can't open and lock privilege tables: Incorrect file format 'db'" | The data directory holds about 25 other projects' databases; recovery can lose data | Not repaired. Local development uses SQLite; CI uses MySQL 8.4. **Owner action:** back up `C:\xampp\mysql\data` before any repair |
| Avast "Web/Mail Shield" intercepts HTTPS | PHP/Composer TLS verification failed | Exported the Windows trusted roots to `.tools/cacert.pem` (git-ignored) and used them through `.tools/php.sh` |
| PHP 8.2 locally; Laravel 13 needs PHP 8.3+ | Project scaffolded on Laravel 12.71 (security support ends about Feb 2027) | Upgrade planned in Phase 02 (ARCHITECTURE.md §7) |
| `intl` and `sodium` are disabled in XAMPP `php.ini` | Needed by Laravel | Enabled per command in `.tools/php.sh`; global `php.ini` untouched |
| Slow disk I/O (antivirus) | Seeder about 5 min; phpstan parallel workers hang | Documented in CLAUDE.md; long commands run in the background |
| Next.js 16 Cache Components are incompatible with static export | Build failure | Disabled (`cacheComponents: false`) |
