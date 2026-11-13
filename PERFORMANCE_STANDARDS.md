# Performance Standards

## 1. Targets

| Metric | Target |
|---|---|
| API p95 latency (reads) | < 300 ms |
| API p95 latency (writes) | < 500 ms |
| Queries per request | ≤ 15 for lists, ≤ 25 for detail pages |
| List page payload | < 200 KB |
| Frontend LCP (dashboard, 4G) | < 2.5 s |
| Frontend JS per route (gzipped) | < 250 KB initial |

## 2. Database
- **N+1 is a bug.** In local and CI environments, `Model::preventLazyLoading()` is on and throws an exception on lazy loads. Production only logs them.
- Every list endpoint uses a Query class with explicit `with([...])` and `select` of the needed columns.
- Use `withCount` / `withSum` instead of loading relations to count them.
- All lists are paginated: default 25, max 100. Use cursor pagination for large append-only data (audit logs, notifications).
- Indexes follow the query patterns in DATABASE_DESIGN.md. New filters need an `EXPLAIN` check in the pull request description. No full table scans on tables expected to exceed 10k rows.
- Keep transactions short: no HTTP calls, file uploads or PDF generation inside a DB transaction.
- Reports run on read-optimised queries, and on a read replica if load needs it later.
- The slow query log is enabled in staging (> 200 ms).

## 3. Caching (database store on shared hosting, Redis on a VPS)

The database cache store is slower than Redis, so cache only what is read often and computed expensively. Run `config:cache`, `route:cache`, `event:cache` and `view:cache` on every deploy, and enable OPcache if the host allows it.

| Data | Strategy | TTL / invalidation |
|---|---|---|
| Permissions per user | Spatie cache | Cleared on role or permission change |
| Active price lists per page | Cache by `page_id` | Cleared on price list change |
| Reference data (sizes, product types, defect types) | Cache | Cleared on change |
| Dashboard counters per branch | Cache | 60 s TTL plus event-based clear |
| Sessions, rate limits, locks | Cache store (database / Redis) | — |

Never cache authorization decisions about specific records, or financial totals used for writes.

## 4. Queues
Anything slower than about 200 ms or touching external services runs in a queued job: PDF generation, email/SMS/WhatsApp, imports, exports, thumbnails, virus scans, broadcasts. Jobs must be idempotent, set `tries` and `backoff`, and use `ShouldBeUnique` where duplicates are harmful.

On shared hosting the queue is processed by cron once a minute (`queue:work --stop-when-empty --max-time=50`), so:
- No job may run longer than about 45 s. Split large imports and exports into chunked jobs (`Bus::batch`).
- The UI shows a "processing" state for queued results (PDFs, imports) and polls for completion.
- A scheduled check alerts managers by email if `failed_jobs` grows or jobs wait longer than 5 minutes.

On a VPS, Horizon replaces this.

## 5. API payloads
- Resources return only the fields the screen needs. Use separate list and detail resources.
- Return money as integer minor units plus a formatted string, and dates as ISO 8601 UTC.
- Enable gzip/brotli at Nginx. Use ETag/`Cache-Control` for reference data endpoints.

## 6. Frontend
- TanStack Query with sensible `staleTime` (reference data 10 min, lists 30 s) and query keys that include filters.
- Avoid unnecessary re-renders: stable query keys, memoised derived data, no large objects in context, and React Hook Form for big forms (garment lists can have hundreds of rows).
- Long tables (garment list entries, audit log) use virtualization when there are more than 200 rows. **Not met yet** for the garment list (up to 2,000 rows rendered; Phase 09 deferred its search with `useDeferredValue` and memoised bulk-paste parsing) — KNOWN_LIMITATIONS.md §4.
- Route-level code splitting. Heavy libraries (charts, XLSX parsing, PDF viewer) are loaded dynamically.
- Images use `next/image` with explicit sizes. Design previews use thumbnails, and full files only on request.
- When notifications arrive (by polling now, or realtime later), they invalidate specific query keys and never trigger a full refetch.

## 7. Uploads
With S3-compatible storage, large design files go directly to storage with presigned URLs, so they never pass through PHP. With local storage on shared hosting, design files are uploaded in chunks (for example 5 MB) to stay within PHP limits and request timeouts. Payment slips under 10 MB may go through the API in one request.

## 7a. Polling
The bell polls `GET /notifications/poll?since=` every 20 s (unread count + rows newer than `since`), the dashboard every 60 s. Both stop when the tab is hidden; list screens do not poll. Phase 09 measured the poll at 256 ms with a filesort on 500k notifications and fixed it with `notifications(notifiable_type, notifiable_id, created_at)` (< 0.1 ms) — at 20 s per open tab this query dominates background load.

## 7b. Phase 09 measurements
- Database: DATABASE_DESIGN.md §7a (EXPLAIN/ANALYZE on 200k orders, 300k payments, 500k notifications; payment queue 7.4 s → 2.8 ms, payment list 11.6 s → 0.6 ms, order list 208 ms → 0.3 ms).
- Frontend static export: 4.6 MB in total; 200–260 KB gzip of JavaScript per page (login 234 KB, dashboard 202 KB, order detail 261 KB, bulk job 216 KB), of which ~110 KB raw is the legacy `nomodule` polyfill that modern browsers do not run. Every page is a static shell; data is fetched client-side through TanStack Query (30 s default `staleTime`, no refetch on focus, no retries on 4xx). List searches are debounced (`useDebounced`, 300 ms). Images use `unoptimized` (static export) — previews are served at upload size.

## 8. Verification
- Feature tests assert query counts on key list endpoints (`DB::getQueryLog`) to catch N+1 regressions.
- Load test before go-live (k6), **against the real shared hosting plan**, because CPU and process limits there decide the real ceiling: 50 concurrent users on order list, order detail, payment verification and status transitions, with p95 within targets.
- Laravel Telescope locally only; Pulse or an APM in production.
