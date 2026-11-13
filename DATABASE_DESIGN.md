# Database Design

**Status:** implemented in Phase 01 (`backend/database/migrations`, 24 migration files, 75 tables including framework and Spatie tables). Diagram: `docs/ERD.md`.

**Phase 02 changes** (`2026_11_10_000001_add_identity_management_columns`):
- `users`: `session_version` (incremented to revoke all sessions), `password_changed_at`, `deactivated_at`.
- `roles`: `label`, `description`, `is_system` (seeded roles; cannot be renamed or deleted).
- `pages`: `contact_email`, `contact_phone_e164`, `address`, and pricing defaults `currency`, `default_advance_percent` (CHECK 30–100), `default_delivery_fee_minor`. `code` and `invoice_prefix` are immutable after creation (enforced in the FormRequest).
- `page_user`: `is_owner` (Page Owners of the page), index `(page_id, is_owner)`.
- `bulk_assignments`: `bulk_designer_id` (assigned internal/external bulk designer), index `(bulk_designer_id, status)`. Assignee columns used by "assigned only" roles: `design_tasks.designer_id`, `bulk_assignments.bulk_designer_id`, `cutting_jobs.supervisor_id`.

**Phase 03 changes** (`2026_11_11_000001_add_order_intake_tables`):
- `customers`: `whatsapp_e164`, `page_id` (customer's page, scopes visibility), `branch_id`; indexes `(page_id, phone_e164)`, `(page_id, whatsapp_e164)`, `(page_id, name)`. The order form's "Customer Address" is stored in `address_line1`.
- `order_types` (code, name, is_active, sort_order); `orders.order_type_id` (nullable only for pre-Phase-03 rows), index `(status, created_at)`; `orders.order_no` widened to 60 on MySQL for `{BRANCH}-{PAGE}-{YY}-{NNNNN}`.
- `urgent_approvals`: `reference_date`, `superseded_at` (a due-date change supersedes the request). New `urgent_approval_decisions` (one row per slot `second_admin` / `operation_admin`; UNIQUE `(urgent_approval_id, slot)` and `(urgent_approval_id, decided_by)` so two different people decide).
- `payments.verification_notes`; new append-only `payment_verification_events` (submitted / verified / rejected, actor, notes). `payments.verified_by` holds the decider for both confirmations and rejections.
- `sequences` scopes in use: `('order', 0, year)` for job numbers, `('customer', 0, 0)` for customer codes.
- Product categories are `product_types`; categories created from the UI use the `Standard` size chart until size charts are configured.

**Phase 04 changes** (`2026_11_12_000001_add_design_garment_pricing_tables`):
- `app_settings` (key → scalar value): responsible designer, responsible bulk designer, correction limit.
- `orders`: `material_id` (fabric type), `material_details`, `design_instructions` (TEXT), `custom_notes`, `overall_remarks`, `design_attachment_document_id`, `details_submitted_at/by`.
- `design_tasks`: `correction_rounds`, `final_version_id`, `completed_at`; `design_versions`: `previous_version_id`, `external_url` (never fetched), `is_final`; `design_correction_requests`: `design_task_id`, `round_no`, `requested_by`, `override_reason`.
- Configurable list fields: `garment_option_sets`, `garment_options`, `garment_field_definitions` (per product type); `garment_list_items` lost `fit`/`sleeve` (now values); new `garment_list_item_values` (item × field key → value, a typed EAV justified by client-configurable columns; no JSON); `garment_list_versions.total_quantity`.
- Pricing: `price_list_items.branch_id`, `label`, and the old unique key dropped (conditions make it non-unique; duplicates rejected in `SavePriceList`); `price_list_item_conditions` (field key = option code).
- `invoices`: `price_list_id`, `garment_list_version_id`, `pricing_date`, `tax_percent`; `invoice_items`: `product_type_id`, `price_list_item_id`. Sequence scope `('invoice', page_id, year)`.
- `bulk_jobs` (UNIQUE `order_id`): the eligible bulk task, separate from `bulk_assignments` (which record internal/external assignment in Phase 08). This replaces the Phase 00 note that a bulk job row would duplicate the assignment: eligibility must be recorded before any assignment exists.
- `order_items` keep the intake estimate (unit price × gross quantity); after list confirmation the invoice lines are the priced snapshot and the order's totals follow the invoice.

**Phase 05 changes** (`2026_11_13_000001_add_bulk_production_workflow_tables`):
- `bulk_jobs` now carries the whole-order assignment and the bulk workflow: `mode` (internal | external, null until assigned), `supplier_id`, `assigned_by/at`, `assignment_notes`, `version` (optimistic lock), `current_version_id`, `approved_version_id`, `approved_list_version_id` (the confirmed list version the approved bulk was prepared from, "locked" at approval), `approved_by/at`, `correction_rounds`, `completed_by/at`, `completion_notes`; indexes `(mode, status)`, `(supplier_id, status)`. `bulk_designer_id` is the internal assignee (null for external jobs). `bulk_assignments` (quantity splits to units/suppliers) stays unused until a split is needed (Q14).
- `bulk_job_assignments`: append-only assignment history (mode, designer/supplier, assigned by, notes, reason, `ended_at` = null for the current row).
- `bulk_versions` (immutable, UNIQUE `(bulk_job_id, version_no)`, `previous_version_id`, `garment_list_version_id`, `is_final`, `external_url`, `transfer_url` = WeTransfer, never fetched) + `bulk_version_files` (→ `documents`, kind preview | file) + `bulk_reviews` (append-only decisions: approved | changes_requested, reason, round, `is_system` for list-correction invalidations).
- `bulk_measurement_fields` (configurable: key, label, unit, optional product type, min/max, decimals, required, `is_system` for bulk/patch meter, `show_on_job_card`), `bulk_job_measurements` (current value per job × field, UNIQUE; the authoritative source of the Job Card) and `bulk_job_measurement_changes` (append-only: old → new, who, reason, `is_correction`). Measurements are DECIMAL(12,3), not money.
- `bulk_job_documents`: external evidence and client checklist confirmation evidence (→ `documents`, private disk).
- `checklist_runs`: `bulk_job_id`, `superseded_at` (mode switched), client confirmation (`client_confirmed_at/by`, `client_channel`, `client_comment`, `client_evidence_document_id`). `checklist_run_items`: snapshot `label`, `is_required`, `sort_order`, `is_completed`, `completed_by/at`; `result` became nullable (bulk items use the completed flag).
- New document categories `bulk_preview`, `bulk_file`. The Job Card is a read model (`JobCard` query), not a table.

**Phase 07 changes** (`2026_11_15_000001_add_cutting_quality_issue_tables`):
- `cutting_jobs`: CHECK `chk_cj_target` dropped and `mode` nullable (the cutter is a user, not a unit/supplier); `status` widened to 30; `version`, `assigned_by/at`, `expected_completion_date` (DATE, Colombo), `assignment_notes`, `completed_by`, `completion_notes`; UNIQUE `print_job_id`; indexes `(supervisor_id, status)`, `(status, expected_completion_date)`; CHECK `cut_quantity ≤ planned_quantity`.
- New `cutting_job_assignments` (append-only), `cutting_job_quantities` (UNIQUE `(cutting_job_id, size_label)`, CHECK `cut ≤ expected`), `cutting_job_events` (append-only status history).
- `checklist_runs.cutting_job_id` (index `(cutting_job_id, stage)`); `checklist_run_items.responsible_user_id`.
- `production_issues`: see §6; indexes `(order_id, status)`, `(cutting_job_id, status)`, `(assigned_to, status)`, `(type, status)`. New `production_issue_events`. New document category `issue_photo` (JPG/PNG/WebP ≤ 10 MB).

**Phase 08 changes** (`2026_11_16_000001_add_sewing_delivery_outbox_tables`):
- `sewing_jobs`: CHECK `chk_sj_target` dropped, `mode` nullable (Q21 open), `status` widened to 30 (`pending | in_progress | completed`), `version`, `started_by`, `completed_by`, `notes`; UNIQUE `cutting_job_id`; index `(status, created_at)`.
- `deliveries`: `method` nullable until dispatch / pickup, `status` widened (`ready | dispatched | delivered`), `version`, `dispatched_by`, `delivered_by`; UNIQUE `order_id`; index `(status, delivered_at)`. COD collections are `payments` rows with `method = cod` and `delivery_id`.
- New `delivery_documents` (delivery, document → `documents`, type `delivery_sheet | waybill | delivery_photo | customer_photo`, notes, uploaded_by, created_at; index `(delivery_id, type)`).
- `orders`: `completed_by`, `completion_notes` (`completed_at` existed); index `(status, completed_at)` for completion reports.
- New `notification_outbox` (one row per recipient: `notification_id` UUID UNIQUE = the in-app notification id, `user_id`, `event`, JSON `payload` (display data, allowed exception like `notifications.data`), `status pending | sent | failed`, `attempts`, `available_at`, `sent_at`, `last_error`; index `(status, available_at)`).
- Reports and dashboards add no tables: aggregates use the indexes above and are cached in the cache store (60 s dashboard sections, 10 min report aggregates).

Target: **MySQL 8 (8.4 LTS preferred), InnoDB, utf8mb4.** The default collation is `utf8mb4_unicode_ci` because the shared host may run MariaDB; set `DB_COLLATION=utf8mb4_0900_ai_ci` on MySQL 8. Local development, the test suite, CI (MySQL 8.4) and production all use MySQL; SQLite is not supported.

## 1. Global standards

| Topic | Standard |
|---|---|
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT`; composite PKs for pure pivots (`branch_user`, `page_user`, `sequences`) |
| Foreign keys | Always declared. `RESTRICT` by default. `CASCADE` only for pure child rows (pivots, price-list rules, draft line items) |
| Money | `BIGINT UNSIGNED` minor units, suffix `_minor`, plus `currency CHAR(3)` on the owning document. Never float or decimal arithmetic in PHP/JS |
| Quantities | `INT UNSIGNED` / `SMALLINT UNSIGNED` |
| Measurements | `DECIMAL(7,2)` (unit in `measurement_points.unit`, default cm) |
| Status | `VARCHAR(16–32)` mapped to PHP backed enums (`app/Modules/*/Enums`); never MySQL `ENUM` |
| Timestamps | UTC `TIMESTAMP` (`APP_TIMEZONE=UTC`, connection `timezone=+00:00`) |
| Business dates | `DATE`, interpreted as Asia/Colombo calendar dates (`required_delivery_date`, `holiday_date`, `due_date`) |
| Optimistic locking | `version INT UNSIGNED` on `orders`, `design_tasks`, `garment_lists` |
| JSON | Only `audit_logs.changes`, `notifications.data` and framework tables |
| Polymorphic columns | Only framework/Spatie tables, `notifications` and `audit_logs`, all using the **enforced morph map** (`App\Support\Database\MorphMap`). Business ownership always uses real FKs |
| Human numbers | `order_no` (globally unique "Job No."), `invoice_no` (per page per year), `gate_pass_no` (per branch per year), generated from `sequences` under `SELECT … FOR UPDATE` |
| CHECK constraints | Added through `App\Support\Database\CheckConstraint` (MySQL 8.0.16+ / MariaDB; the rules are also enforced in Actions) |

## 2. Branch and page scoping (no data leakage)

- Users are assigned to branches (`branch_user`) and pages (`page_user`). **Deny by default:** without assignments, and without `scope.all_branches` / `scope.all_pages`, a user sees nothing.
- Tables that are listed directly carry a denormalised, immutable `branch_id` copied from the order: `orders`, `payments`, `invoices`, `deliveries`, `gate_passes`, `bulk_assignments`, `print_jobs`, `cutting_jobs`, `sewing_jobs`, `qc_inspections`, `production_issues`.
- `orders`, `invoices` and (Phase 09) `payments` also carry `page_id`. The other tables get their page scope **through `order_id`** (a subquery on the indexed `orders.page_id`).
- Why `payments.page_id` (Phase 09): MySQL/MariaDB run `order_id IN (SELECT id FROM orders WHERE page_id IN …)` (and the `EXISTS` form) as a semi-join driven by `orders`. With 300k payments the page-scoped verification queue took 7.4 s and the payment list 11.6 s. Payments are the only via-order table with many rows per order; with the copied `page_id` both take under 3 ms. An order never changes page (`page_id` is prohibited on update), so the copy cannot drift. Copy `page_id` to another via-order table only after measuring it.
- The `App\Support\Authorization\BranchScoped` trait implements `scopeVisibleTo($user)` for lists and `isVisibleTo($user)` for policies. `ScopedPolicy` turns an out-of-scope record into 404.
- Customers, suppliers and reference data are company-wide. Access to a customer's **orders** is still branch/page scoped.

## 3. Mapping from the candidate entity list

| Candidate | Implemented as | Reason |
|---|---|---|
| jobs, job_items | `orders`, `order_items` | `jobs` is Laravel's queue table. "Job No." = `orders.order_no` |
| job_assignments, bulk_jobs, bulk_assignments | `bulk_assignments` | The bulk stage belongs to the order; a separate `bulk_jobs` row would duplicate it |
| payment_verifications | columns on `payments` (`verified_by`, `verified_at`, `rejection_reason`) | Exactly one decision per payment; a rejected slip is a new payment |
| pricing_tables, pricing_rules | `price_lists`, `price_list_items`, `price_list_option_prices` | Per page; rules by product type × material × quantity tier |
| design_tasks, design_versions, design_correction_requests | same names + `design_reviews`, `design_version_files` | Reviews record the customer decision against one version |
| garment_lists, garment_list_versions, garment_list_items | same names | Items belong to a version; locked versions are immutable |
| bulk_checklists, cutting_checklists | `checklist_templates`, `checklist_template_items`, `checklist_runs`, `checklist_run_items` | One relational model for all stages (no JSON) |
| bulk_files | `bulk_assignment_documents` (pivot to `documents`) | |
| bulk_correction_requests, production_issues | `production_issues` (`type = correction_request`, …) | One table for problems raised at any stage |
| printing_jobs, printing_samples, printing_approvals | `print_jobs`, `print_samples` (decision columns) | One decision per sample |
| cutting_jobs, cutting_assignments | `cutting_jobs` (internal unit **or** supplier) | The job is its assignment |
| — (client: sewing) | `sewing_jobs` | Confirmed stage |
| delivery_records | `deliveries` (+ COD columns) | |
| activity_logs, audit_logs | `audit_logs` | One append-only trail; the activity feed is a query over it |
| user_page_assignments | `page_user` | |
| Financial status | `orders.payment_status` (+ `verified_paid_minor`) | Maintained in the same transaction as payment changes; checked nightly |
| Workflow status | `orders.status` (`OrderStatus` enum + transition map) | |
| Assigned department | **Derived** from status (`OrderStatus::department()`) | Storing it would duplicate the status |
| Product category, material | `order_items.product_type_id`, `order_items.material_id` | Per line, since one order can hold several products |
| Quantity | `order_items.quantity`; `orders.total_quantity` cached for lists | |

## 4. Tables by module

| Module | Tables |
|---|---|
| Identity | `users`, `password_reset_tokens`, `sessions`, Spatie `roles` / `permissions` / `model_has_*` / `role_has_permissions`, `personal_access_tokens` (unused by the SPA) |
| Organizations | `organizations` (single row), `branches`, `pages`, `branch_user`, `page_user`, `working_calendar_days`, `holidays`, `sequences` |
| Customers | `customers` |
| Documents | `documents` |
| Pricing | `size_charts`, `sizes`, `product_types`, `materials`, `product_options`, `price_lists`, `price_list_items`, `price_list_option_prices` |
| Orders | `orders`, `order_items`, `order_item_options`, `order_status_histories`, `urgent_approvals` |
| BulkProduction | `production_units`, `suppliers`, `measurement_points`, `size_specs`, `checklist_templates`, `checklist_template_items`, `bulk_assignments`, `bulk_assignment_documents`, `bulk_measurements`, `checklist_runs`, `checklist_run_items`, `production_issues`, `production_issue_events` (Phase 07) |
| Design | `design_tasks`, `design_versions`, `design_version_files`, `design_reviews`, `design_correction_requests` |
| Garments | `garment_lists`, `garment_list_versions`, `garment_list_items` |
| Printing | `print_jobs`, `print_samples`, `print_sample_documents`, `print_job_events`, `print_job_gate_passes` (Phase 06) |
| Cutting / Sewing | `cutting_jobs`, `cutting_job_assignments`, `cutting_job_quantities`, `cutting_job_events` (Phase 07), `sewing_jobs` |
| QualityControl | `defect_types`, `qc_inspections`, `qc_defects` |
| GatePass | `gate_passes`, `gate_pass_lines` |
| Delivery | `deliveries` |
| Payments | `payments`, `refunds` |
| Invoices | `invoices`, `invoice_items` |
| Notifications | `notifications`, `notification_preferences` |
| Audit | `audit_logs` |
| Framework | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` |

## 5. Constraints enforced by the database

| Table | Constraint |
|---|---|
| `orders` | `total = subtotal − discount + tax + delivery_fee`; `discount ≤ subtotal`; `advance_percent` 0–100 |
| `order_items` | `quantity ≥ 1`; `line_total = quantity × (unit_price + options_unit)` |
| `invoices`, `invoice_items` | Same total formulas |
| `payments` | `amount > 0`; **`verified_by <> recorded_by`** (separation of duties) |
| `refunds` | `amount > 0` |
| `bulk_assignments`, `sewing_jobs` | `internal` ⇒ unit set and supplier null; `external` ⇒ supplier set and unit null (dropped for `cutting_jobs` in Phase 07: the cutter is a user) |
| `cutting_jobs`, `cutting_job_quantities` | `cut_quantity ≤ planned_quantity` / `cut_quantity ≤ expected_quantity` |
| `production_issues` | `quantity_affected IS NULL OR quantity_affected ≥ 1` |
| `sewing_jobs` | `sewn ≤ received` |
| `gate_pass_lines` | `quantity_out ≥ 1`, `returned ≤ out` |
| `qc_inspections` | `passed + failed = inspected` |
| `garment_list_items`, `qc_defects` | `quantity ≥ 1` |
| `price_list_items` | `min_qty ≥ 1`, `max_qty ≥ min_qty` |
| `audit_logs` | Triggers reject UPDATE and DELETE (MySQL; `AUDIT_DB_TRIGGERS`) |
| Unique keys | `order_no`, `invoice_no`, `gate_pass_no`, `branches.code`, `pages.code`, `pages.invoice_prefix`, `customer_code`, `(design_task_id, version_no)`, `(garment_list_id, version_no)`, `(garment_list_version_id, line_no)`, `(print_job_id, sample_no)`, `payments.idempotency_key`, … |

MySQL forbids `ON DELETE` actions on FK columns used in CHECK constraints, so those FKs (`payments.recorded_by` / `verified_by`, the unit/supplier FKs) declare no `ON DELETE` clause. InnoDB's default is then restrict.

## 6. Invariants enforced in code (not expressible as constraints)
- Assignment quantities per order item equal the item quantity.
- `print_jobs.design_version_id` equals the task's `approved_version_id`, and `garment_list_version_id` is `locked`.
- Phase 06: `print_jobs.bulk_job_id` is UNIQUE (one printing job per completed bulk job) and `bulk_version_id` points at the final internal bulk version (null for external). `print_jobs.version` is the optimistic lock. `print_samples` rows are immutable versions (`sample_no` per job, uploader, notes, decision, `rejection_reason`, `recorded_by` / `decided_at` for the review); `print_jobs.approved_sample_id` points at the approved one. `print_job_events` is the append-only status history; `print_job_gate_passes` holds gate pass details recorded after physical printing (UNIQUE `print_job_id` + `gate_pass_no`).
- Phase 07: `cutting_jobs.print_job_id` is UNIQUE (one cutting job per printing job, opened by the first gate pass); `supervisor_id` is the assigned cutter; `version` is the optimistic lock. `cutting_job_quantities` holds expected (from the locked list, per size) and cut quantity; the total cut never falls below the unresolved defective pieces reported (`production_issues` of the piece-defect categories). `checklist_runs.cutting_job_id` + `stage` (`cutting` | `quality_control`) are the job's checklist and quality-check instances; `checklist_run_items.responsible_user_id` names the person responsible for a task (null = the cutter). `production_issues` gained `cutting_job_id`, `quantity_affected`, `part`, `size_label`, `image_document_id`, `is_blocking`, `assigned_to/at`, `closed_by/at`, `version`; statuses `open | under_investigation | correction_required | resolved | closed`. `production_issue_events` is the append-only issue history.
- Issued invoices and submitted design versions are never updated (except invoice status → `void`).
- `orders.verified_paid_minor` = Σ verified payments − Σ refunds, recalculated in the same transaction and checked nightly.
- Pricing rules with `material_id = NULL` cannot be made unique by MySQL (NULLs are distinct), so duplicates are rejected in the pricing Action.
- Overlapping price-list validity per page is rejected in the Action.

## 7. Index strategy
- Phase 09 (measured, §7a): `orders (created_at)`, `payments (created_at)`, `payments (status, created_at)`, `payments (page_id, status, created_at)`, `notifications (notifiable_type, notifiable_id, created_at)`. Removed as redundant: `deliveries_order_id_index` (duplicate of the unique index) and `notifications (notifiable_type, notifiable_id)` (left prefix of two other indexes). A scan of every index for duplicates and redundant left prefixes found no others.
- Composite indexes lead with the scope column, followed by the filter and then the sort: `orders (branch_id, status, required_delivery_date)`, `(page_id, status, required_delivery_date)`, `(branch_id, page_id, created_at)`, `(branch_id, payment_status)`, `payments (branch_id, status, created_at)`, `gate_passes (branch_id, status, expected_return_date)`, `deliveries (branch_id, status, scheduled_date)`, `audit_logs (subject_type, subject_id, occurred_at)`.
- Every FK is indexed (InnoDB creates the index if the FK column does not lead an existing one).
- A new filter or sort needs a matching index and an `EXPLAIN` in the pull request.

## 7a. Phase 09 performance audit (EXPLAIN / ANALYZE)

Volume copy: 200k orders, 200k order items, 300k payments, 30k customers, 500k notifications (separate database, synthetic data). The local server is MariaDB 10.4 with a 16 MB buffer pool, so full scans are disk-bound; production MySQL 8 with a larger pool will scan faster, but the access paths are the same. Times are `r_total_time_ms` from `ANALYZE FORMAT=JSON`.

| Query (endpoint) | Before | After | Change |
|---|---|---|---|
| Orders list, global scope, newest first (`GET /orders`) | 208 ms (full index scan + filesort) | 0.3 ms | `orders(created_at)` |
| Orders list, page 101 (OFFSET 2500) | 4.1 s | 27 ms | same |
| Orders list, page-scoped (2 pages) | 228 ms | 0.1–14 ms | same (walks `created_at`, filters the page) |
| Orders "approved" group (9 statuses) | 247 ms | 10 ms | same |
| Payment verification queue, page-scoped (`GET /payments?filter[status]=pending_verification`) | 7,416 ms | 2.8 ms | `payments.page_id` + `(status, created_at)` |
| Payments list, page-scoped | 11,560 ms | 0.6 ms | `payments.page_id` + `(created_at)` |
| Payments list count, page-scoped | 3,090 ms | 77 ms | `(page_id, status, created_at)` |
| Payment queue, global | 206 ms | 0.1 ms | `(status, created_at)` |
| Notification history (`GET /notifications`) | 247 ms + filesort | 0.1 ms | `notifications(notifiable…, created_at)` |
| Notification poll, every 20 s per user (`GET /notifications/poll`) | 256 ms + filesort | < 0.1 ms | same |
| Unread count | 2.4 ms | 2.1 ms | unchanged (existing unread index) |
| Orders of one customer | < 0.1 ms | < 0.1 ms | unchanged |
| Free-text order search (`filter[search]`: `order_no LIKE '%…%'` or customer name) | 0.3–4 s (cold) | 0.3–7 s (cold) | **not fixed**: a leading-wildcard search reads every order whatever the index (KNOWN_LIMITATIONS.md) |

Tried and dropped: `orders(page_id, created_at)` (the `created_at` index already serves page-scoped lists in under 15 ms). The dashboard work lists (design, bulk, printing, cutting, delivery) sort small active sets on the joined `orders.required_delivery_date` and were not re-indexed.

## 8. Transactions and concurrency
- Every Action that writes more than one row runs in `DB::transaction()`.
- Workflow transitions: `lockForUpdate()` on the order, plus a `version` check and history and audit rows in the same transaction. Events are dispatched after commit.
- Number generation: `sequences` row `FOR UPDATE` inside the inserting transaction, so numbers are gap-free and never reused.
- `Cache::lock()` (database store on shared hosting) is only an extra guard for long operations; row locks are the real guarantee.
- Lock order: the **order** row first, then the workflow row (payment, print job, cutting job, delivery). `ConfirmGarmentList` locks the list and its version, then the order; no Action locks the order and then the list, so the two cannot deadlock.
- Phase 09 multi-process race tests (`tests/Feature/Concurrency`, `--group=concurrency`) send the same request from several OS processes at the same instant: payment verification, urgent approval slots, list confirmation (invoice issue), bulk job creation, sample approval, cutting completion, job completion and payment creation with one Idempotency-Key. Exactly one wins; the others get a clean 409; there are no duplicate rows, double approvals, deadlocks or 500s. Database backstops: UNIQUE `bulk_jobs.order_id`, `print_jobs.bulk_job_id`, `cutting_jobs.print_job_id`, `sewing_jobs.cutting_job_id`, `deliveries.order_id`, `payments.idempotency_key`, `invoices.invoice_no`, `orders.order_no`.

## 9. Deletion and retention policy

| Data | Delete? | Policy |
|---|---|---|
| Orders, items, payments, refunds, invoices, gate passes, deliveries, QC, production records, status histories | **Never** | Cancel or void with a reason. FKs `RESTRICT` prevent accidental deletes. Keep at least **7 years** (financial records) |
| Design versions, garment list versions | Never | Superseded, not deleted |
| Users | Soft delete (`deleted_at`) | Deactivate first. Soft-deleted users cannot authenticate; history keeps their FK |
| Customers, suppliers, product types, materials | Soft delete | Allowed only when no open orders reference them (checked in the Action) |
| Branches, pages | Deactivate (`is_active=false`) | Never deleted once referenced |
| Documents | Soft delete of metadata; file purge job | Payment slips and invoices are kept with their financial record (7 years). Design files: retention per Q18 (assumption: 3 years after order completion), then purged with an audit entry |
| Audit logs | Never by the application | Append-only. Archive yearly to cold storage after 2 years; keep 7 years |
| Notifications | Prune | Read notifications older than 180 days |
| Sessions, cache, failed jobs, expired tokens | Prune | Scheduler (`queue:prune-failed`, `sanctum:prune-expired`, session GC) |

## 10. Migration standards
- One migration per logical change. Never edit a migration that has run in a **shared** environment (Phase 01 migrations have only run on local/CI databases).
- Every migration has a working `down()`.
- Destructive production migrations need a written plan and a backup.
- On large tables, prefer online DDL (`ALGORITHM=INPLACE, LOCK=NONE`).
