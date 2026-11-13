# Security Standards

Baseline: **OWASP ASVS 4.0 Level 2** and the OWASP Top 10. These rules are mandatory, and reviewers reject pull requests that break them.

## 1. Authentication (ASVS V2, V3)
- Sanctum SPA cookie sessions only. No tokens in `localStorage` or `sessionStorage`.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax`, domain-scoped. Sessions are stored server-side (database on shared hosting, Redis on a VPS) and encrypted (`SESSION_ENCRYPT=true`).
- Session ID regenerated on login and on privilege change. Logout invalidates the server session.
- Idle timeout of 2 hours and an absolute lifetime of 12 hours (configurable).
- Passwords: bcrypt/argon2id via Laravel `Hash`, minimum 10 characters, checked against breached lists (`Password::uncompromised()`).
- Login rate limit: 5 attempts per minute per email+IP, with exponential lockout. Error messages are generic ("Invalid credentials").
- Password reset tokens are single-use and expire after 60 minutes.
- Deactivated users are rejected on every request (middleware), not only at login.
- Session revocation: `users.session_version` is stored in the session at login and checked on every request. Deactivation, password change, password reset, an admin-sent reset link (optional) and an email change increment it, which ends every session on every device regardless of the session driver. A password change keeps the current session (with a new ID).
- Exponential lockout: after 5 failed logins per email + IP the pair is locked for 1, 2, 4 … minutes (max 60), on top of the per-minute limiter.
- New accounts: the administrator enters the initial password (same password policy, confirmed, hashed, never logged or audited; no email is sent; client decision 2026-11-10). Admin resets of existing accounts still send a single-use link, so administrators never see a password the user chose. Forgot-password answers identically for unknown, inactive and active accounts.
- Privilege escalation: administrators can only grant permissions/roles they hold, assign branches/pages inside their own scope, and manage users whose permissions are a subset of theirs; nobody administers their own account.
- TOTP 2FA required for Owner, Branch Manager, Accounts and Super Admin (Phase 15).

## 2. Authorization and IDOR (ASVS V4, OWASP A01)
- **Every** controller action calls a policy (`$this->authorize()` or a FormRequest `authorize()`). A CI test lists all API routes and fails if any route is missing authorization middleware or a policy call.
- Policies check **permission + branch membership + record state**.
- List queries always apply `visibleTo($user)` scoping. A record from another branch returns **404**, not 403, so its existence is not revealed.
- Nested resources are checked against their parent (for example `/orders/{order}/payments/{payment}` verifies `payment.order_id = order.id`). Use scoped route bindings.
- Separation of duties: payment recorder ≠ verifier; invoice issuer cannot void their own invoice without manager permission.
- Permission changes, role changes and branch assignments are audited.
- Every endpoint has a feature test for: no auth → 401, missing permission → 403, other branch → 404.

## 3. Input validation and injection (ASVS V5, OWASP A03)
- All input goes through FormRequest rules with explicit types, lengths and `exists` checks that are themselves branch-scoped.
- Only Eloquent/query builder bindings. Raw SQL (`DB::raw`, `whereRaw`) needs bound parameters and a reviewer sign-off.
- Sort and filter fields come from a whitelist and are never interpolated from user input.
- **Mass assignment:** explicit `$fillable` on every model; `$guarded = []` is forbidden. Actions pass validated DTOs, never `$request->all()`.
- Phone numbers are normalised to E.164. Emails are validated with `email:rfc`.
- Spreadsheet export: cells starting with `= + - @ \t \r` are prefixed with `'` to prevent CSV/formula injection.

## 4. XSS and output (ASVS V5.3)
- React escapes by default. `dangerouslySetInnerHTML` is forbidden unless the content is sanitised with DOMPurify and a reviewer has approved it.
- API returns JSON only, with `Content-Type: application/json` and `X-Content-Type-Options: nosniff`.
- Invoice PDF templates escape all variables (Blade `{{ }}` only, never `{!! !!}` for user data).
- Content-Security-Policy on the frontend, set by `.htaccess` headers: `default-src 'self'`; `connect-src` to the API host; no `unsafe-eval` in production. A static export cannot use per-request nonces, so inline scripts are allowed by hash (generated at build time), not by `'unsafe-inline'`.

## 5. CSRF and CORS
- Sanctum CSRF: the frontend fetches `/sanctum/csrf-cookie` and sends `X-XSRF-TOKEN` on state-changing requests.
- CORS: allowed origin is exactly the frontend origin, `supports_credentials=true`, no wildcard.
- State changes only through POST/PUT/PATCH/DELETE. GET never changes state.

## 6. File uploads (ASVS V12)
- Allow-list per category, checked by **content** (`finfo`), not by extension or client MIME:

| Category | Allowed | Max size |
|---|---|---|
| Payment slip, evidence, photos | JPEG, PNG, WebP, PDF | 10 MB |
| Design preview | JPEG, PNG, WebP, PDF | 25 MB |
| Design source | PDF, AI, PSD, SVG, CDR, ZIP | 200 MB [Q18] |
| Imports | CSV, XLSX | 5 MB |

- Files are stored on a **private** disk under a random UUID path. The original name is kept only as metadata and sanitised on download (`Content-Disposition: attachment`).
- Files are never served from the public web root, and uploaded files are never executed or included.
- SVG files are never rendered inline (stored XSS risk). They are served as attachments.
- Images are re-encoded for previews (thumbnail job), which strips metadata.
- SHA-256 is stored. Shared hosting has no ClamAV, so `scanned_status` is `skipped` and the protection relies on the content-type allow-list, image re-encoding and download-only serving. ClamAV scanning is turned on if the system moves to a VPS.
- On shared hosting, the private storage folder is **outside `public_html`** and also contains a deny-all `.htaccess`, in case it is ever moved under the web root.
- Downloads: authorized endpoint → signed URL valid for 5 minutes.
- XLSX import uses a streaming reader with row limits, which protects against zip bombs.

## 7. Sensitive data and secrets (ASVS V6, V8)
- `.env` is never committed; `.env.example` contains no secrets. Secrets are set on the server or in a secret manager.
- `APP_DEBUG=false` in staging and production. Stack traces are never returned to clients.
- Personal data (customer phone and address) is masked in logs. Passwords, tokens and full card data are never logged. Card data is never stored.
- TLS 1.2+ everywhere, with HSTS on both domains.
- Shared hosting: only Laravel's `public/` folder is web-accessible. `.env`, `storage/`, `vendor/` and source code live outside `public_html`. Use strict file permissions (folders 755, files 644, `.env` 600). Disable directory listing. Use a dedicated MySQL user per environment. Use SFTP/SSH keys only, with no FTP passwords.
- DB and file backups are encrypted at rest. Restore is tested every quarter.

## 8. Business logic integrity
- All money, totals, advance checks, urgency, quantities and transitions are calculated and enforced **on the server**.
- Optimistic locking (`version`) on edits, plus row locks on transitions. Idempotency keys on payment creation.
- Approvals record who, when, through which channel, and the evidence.

## 9. Audit and monitoring (ASVS V7)
- Audit log for every action listed in WORKFLOW_REQUIREMENTS.md §3.14. The log is append-only, and the DB user has no UPDATE or DELETE on it.
- Each log entry includes a request ID, which is also returned in the `X-Request-Id` response header.
- Alerts on repeated failed logins, permission-denied spikes, queue failures and 5xx rate.

## 10. Rate limiting
- Auth endpoints: as in §1. API in general: 120 requests/min per user. Uploads: 20/min per user. Exports and reports: 10/min per user.

## 11. HTTP headers
`Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY` (or CSP `frame-ancestors 'none'`), `Permissions-Policy` restricting camera, microphone and geolocation.

## 12. Dependencies and supply chain (OWASP A06)
- `composer audit` and `npm audit --omit=dev` run in CI and fail on high or critical issues.
- Lock files are committed, and exact versions are installed in CI (`composer install`, `npm ci`).
- Dependabot or Renovate is enabled, with a monthly upgrade window.

## 13. Security review gates
- Every phase ends with a security review of its endpoints (checklist: authorization, validation, IDOR tests, audit, rate limit).
- An external penetration test before go-live is recommended.

## 14. Phase 09 security audit (2026-11-13)

Scope: every API route (185, `php artisan route:list`), all policies, Actions, uploads/downloads, the frontend navigation sinks, configuration, CI/CD and dependencies. Verified by reading the code and by sending the bypass requests through the API (`tests/Feature/Security/BypassAttemptsTest.php`, run in CI).

| # | Finding | Severity | Status |
|---|---|---|---|
| S1 | `RequireAuthorization` counted any Gate call, including plain permission lookups made by list scopes and by every Resource's `abilities`. A controller that forgot `Gate::authorize()` but returned a Resource would have passed the safety net. | Medium (defence in depth) | **Fixed**: `AuthorizationTracker` counts only policy abilities and named Gates; regression test in `RouteAuthorizationTest` |
| S2 | Open redirect after login: `?next=/%09/evil.example` (TAB) or `/%0A/…` passed `safeNextPath()`; browsers strip TAB/LF, so it became `//evil.example`. The same weak check guarded notification and dashboard deep links. | Medium | **Fixed**: shared `lib/safe-path.ts` (rejects control characters, whitespace and backslashes, resolves against a fixed origin); tests |
| S3 | Deploy uploads over FTPS with `ssl:verify-certificate no` (`.github/workflows/deploy.yml`): a network attacker could read the FTP password and inject code into the release. §7 also asks for SFTP/SSH keys, not FTP passwords. | High (supply chain) | **Open** — needs the host's FTP certificate or SSH; see KNOWN_LIMITATIONS.md |
| S4 | Frontend CSP allows `'unsafe-inline'` scripts (static export cannot use nonces). | Medium | Open (Phase 15: build-time hashes) |
| S5 | No 2FA for privileged roles (§1). | Medium | Open (planned Phase 15) |
| S6 | Approval roles: a user holding `scope.all_pages` and an approval permission (`designs.approve`, `bulk.approve`, `printing.approve_sample`) skips the Page-Owner check by design. Only Super Admin has that combination by default; a custom role could get it. | Low | By design; documented. Review custom roles |
| S7 | Dev-only npm advisories: 8 moderate (`minimatch` ReDoS via the ESLint toolchain). Production dependencies: 0 (`npm audit --omit=dev`); Composer: 0. | Low | Open — fix needs a major `eslint-config-next` change; not shipped in the export |
| S8 | `RoleController`: an administrator can remove a permission they hold from a role that is also assigned to a more privileged user (removal is allowed when the actor holds the permission). | Low | Open — accepted: only role administrators (`roles.manage`) can do it, and it is audited |

Verified and holding (no change needed): branch/page isolation returns 404 for every record type and every list (orders, payments, invoices, design, bulk, printing, cutting, sewing, delivery, garment lists, job cards); nested IDs are checked against their parent (documents, checklist items, price-list rules); signed download URLs also re-run the policy, expire after 5 minutes and reject tampered signatures; separation of duties (payment recorder ≠ verifier, two different urgent approvers, the COD collector cannot verify); Page-Owner-only approvals; locked garment lists refuse every edit path including CSV import; server-owned fields (`status`, totals, `advance_percent`, `verified_paid_minor`, `page_id`) are rejected with 422; discounts need `orders.discount`; payments must be > 0 and ≤ the open balance, COD cannot be recorded from the order screen, Idempotency-Keys are bound to the order and the recorder; uploads are type-checked by content (PHP-as-PNG, SVG and HTML are refused); no raw SQL with user input (all `selectRaw`/`whereRaw` use constants or bindings); no `{!! !!}`, no `dangerouslySetInnerHTML`; external links (Drive, WeTransfer) are allow-listed https URLs that the server never fetches (no SSRF); CSV export neutralises formulas; audit log is append-only (model guard + DB triggers); no secrets in git; session cookies are Secure/HttpOnly/SameSite=Lax/encrypted with idle + absolute timeouts and session-version revocation.
