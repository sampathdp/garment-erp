# Workflow Requirements

This document turns the client's workflow list into precise, testable rules. Items marked **[ASSUMPTION]** are working assumptions until the client confirms them. Items marked **[Q#]** link to the open questions in section 6.

## 1. Actors and roles

Initial roles (client list, Phase 02). Seeded by `PermissionSeeder` from `App\Modules\Identity\Support\Roles`; administrators can edit their permissions (except Super Admin, which is locked) and add more roles.

| Role (`key`) | Data scope | Main responsibilities |
|---|---|---|
| Super Admin (`super_admin`) | everything | System configuration, users, roles, branches, pages. Holds every permission. |
| Page Owner (`page_owner`) | assigned pages, all branches | Orders of the page, customer information, design instructions, unlocked garment lists, design / bulk / printing-sample approval. Records advance payments. |
| Second Admin (`second_admin`) | assigned pages, all branches | Verifies payments (never ones they recorded), refunds, invoices |
| Design Designer (`design_designer`) | assigned tasks only | Assigned design tasks: uploads versions, responds to correction requests |
| Bulk Designer (`bulk_designer`) | assigned jobs only | Assigned bulk jobs: measurements, bulk files, bulk checklists, issue reports |
| Operation Admin (`operation_admin`) | everything | Operational jobs, internal/external bulk assignment, printing, printing samples, cutting, sewing, production issues |
| External Bulk Designer (`external_bulk_designer`) | assigned jobs only | As Bulk Designer, for external (supplier-side) staff |
| Cutting Staff (`cutting_staff`) | assigned jobs only | Assigned cutting jobs: cutting checklists, issue reports |
| Delivery Staff (`delivery_staff`) | assigned branches, all pages | Dispatch, delivery confirmation, cash-on-delivery recording |

Roles are bundles of permissions (Spatie). Code checks **permissions**, never role names. Data scope works on two dimensions, branches and pages: a user sees a record only if both its branch and its page are assigned to them (or the role holds `scope.all_branches` / `scope.all_pages`). Task roles hold `*.view_assigned` instead of `*.view` and see only records whose assignee is them. Every check order is: scope (404) → assignment (404) → permission (403) → record state (409). The central policies live in each module's `Policies/` folder (`OrderPolicy`, `DesignTaskPolicy`, `GarmentListPolicy`, `BulkAssignmentPolicy`, `PrintJobPolicy`, `CuttingJobPolicy`, `PaymentPolicy`, `ProductionIssuePolicy`); later phases must call them from every endpoint.

The Phase 00 role list (Owner, Branch Manager, Sales, Accounts, Design Lead, …) is replaced by the list above. Where later sections say "shop manager" or "Branch Manager", the permission (`orders.urgent.approve_admin` / `orders.urgent.approve_operations`, `gate_passes.approve`, …) is what matters; which roles hold it is configurable.

## 2. Order lifecycle (state machine)

### 2.1 States

```
draft
pending_payment_verification   (submitted; verified advance still below the required 30%)
payment_rejected               (latest payment rejected, nothing else waiting for verification)
urgent_approval_pending        (urgent: waiting for the Second Admin AND the Operation Admin)
urgent_approval_rejected       (urgent: one of them rejected; re-evaluated when the due date changes)
confirmed                      ("Approved Order": urgent approvals complete (if urgent) AND verified advance ≥ required)
design_in_progress
design_review              (version sent to customer)
design_approved
bulk_assigned              (internal or external production decided)
bulk_preparation           (measurements + checklists)
print_preparation
print_sample_review
printing
cutting
sewing
quality_check
ready_for_delivery
out_for_delivery
delivered
completed
on_hold                    (remembers previous state)
cancelled
```

The client confirmed that sewing is a stage. It is placed between `cutting` and `quality_check` **[ASSUMPTION on position, Q21]**.

### 2.2 Transitions

| From | To | Guard (enforced on the backend) | Permission |
|---|---|---|---|
| draft | pending_payment_verification / urgent_approval_pending | customer, job specification and price set; due date after today; urgency evaluated on the server | `orders.submit` |
| pre-approval states (the four above) | each other / confirmed | derived by `EvaluateOrderRelease` after every payment recorded or decided, urgent decision, due-date or price change (see §3.2–3.3). `urgent_approval_rejected` never goes straight to `confirmed`. | system |
| confirmed | design_in_progress | designer assigned | `designs.assign` |
| design_in_progress | design_review | a submitted design version exists | `designs.submit` |
| design_review | design_in_progress | customer requested changes (correction items recorded) | `designs.record_review` |
| design_review | design_approved | customer approved a specific version | `designs.record_review` |
| design_approved | bulk_assigned | assignments cover the full ordered quantity | `production.assign` |
| bulk_assigned | bulk_preparation | — | `production.manage` |
| bulk_preparation | print_preparation | required checklist items passed; measurements recorded; **garment list locked** | `production.manage` |
| print_preparation | print_sample_review | sample created from the approved design version and the locked list version | `printing.manage` |
| print_sample_review | print_preparation | sample rejected | `printing.record_sample` |
| print_sample_review | printing | sample approved | `printing.record_sample` |
| printing | cutting | printed quantity recorded; any external gate pass returned or closed | `printing.manage` |
| cutting | sewing | cut quantity recorded | `cutting.manage` |
| sewing | quality_check | sewn quantity recorded; any external gate pass returned or closed | `sewing.manage` |
| quality_check | ready_for_delivery | passed quantity = ordered quantity, or shortfall approved by a manager | `qc.inspect` |
| quality_check | printing / cutting / sewing | rework required (defects recorded) | `qc.inspect` |
| ready_for_delivery | out_for_delivery | delivery record created; COD amount set to the outstanding balance (balance does **not** need to be paid first) | `delivery.dispatch` |
| out_for_delivery | delivered | proof of delivery recorded; COD collection recorded as a payment pending verification | `delivery.confirm` |
| ready_for_delivery | delivered | customer pickup with proof; balance paid at pickup recorded as a payment | `delivery.confirm` |
| delivered | completed | verified balance = 0 (COD verified by accounts), or write-off approved | `orders.complete` |
| any active | on_hold | reason required | `orders.hold` |
| on_hold | previous state | — | `orders.hold` |
| draft … print_preparation | cancelled | reason required; refund handling recorded | `orders.cancel` |
| printing or later | cancelled | reason + manager approval | `orders.cancel_late` |

Rules:
- Transitions happen only through the `TransitionOrder` action. There is no direct `status` update anywhere.
- Each transition runs in a DB transaction, locks the order row (`lockForUpdate`), checks the expected `version`, writes `order_status_histories`, writes the audit log, and dispatches a domain event after commit.
- Invalid transitions return HTTP 409 with a machine-readable reason.

## 3. Business rules (invariants)

### 3.1 Order creation
- An order belongs to exactly one **branch** (where production is managed) and one **page**. A page is a **Facebook page** that sells the products and has its own pricing and invoice identity (confirmed). Whether one page can sell for several branches, and whether staff are limited to certain pages, is still open **[Q1]**.
- The system serves **one company with several branches** (confirmed). It is not multi-tenant.
- Unit prices come from the page's active price list at submission time and are **snapshotted** on the order item. Later price list changes never change existing orders.
- Every total is calculated on the backend. Totals sent by the frontend are ignored.
- An order may contain several items (for example jerseys + shorts) **[Q14]**.

### 3.2 Advance payment
- `advance_required_minor = ceil(total_minor × advance_percent / 100)`, in integer arithmetic. `total_minor` **includes delivery fee and tax** (confirmed).
- `advance_percent` defaults to 30 and is snapshotted on the order. It can never be lower than 30 unless a permission-gated exception is approved and audited **[Q2]**.
- Only payments with status `verified` count. Recording a payment (sales) and verifying it (accounts) must be done by different users — **separation of duties**.
- Payment slip upload is required for bank transfers.
- If the order total increases after confirmation (for example the list grows), the advance is re-checked. If it falls short, the order cannot move past `design_approved` until it is topped up **[ASSUMPTION, Q2]**.
- Payment creation accepts an `Idempotency-Key` header, so a double submit does not record two payments.
- **Phase 03 implementation:** `required = ceil(invoice_total × advance_percent / 100)` in integer minor units (`AdvanceRule`), `advance_percent` = the page default (minimum 30) snapshotted at creation. Several advance payments are allowed; only `verified` ones are summed. A payment cannot exceed the unpaid balance (verified + waiting payments).
- **Verification ≠ eligibility:** confirming a payment records that the money arrived; the job becomes eligible for production only when the SUM of verified payments ≥ required advance (and, if urgent, both approvals are given). A verified payment below 30% keeps the order in `pending_payment_verification`, shows the missing amount, and notifies the Page Owners and the sales owner. The Operation Admin is notified only when they have already approved the order as urgent (they planned production around it).
- Evidence (slip/screenshot, JPG/PNG/WebP/PDF ≤ 10 MB, checked by content) and a reference are required for bank transfers, online payments and cheques. Evidence is stored on the private disk and served only through a 5-minute signed URL that also re-checks the payment policy.
- Every payment has an append-only verification history (`payment_verification_events`: submitted, verified, rejected, with actor and notes). A decision is final; a corrected slip is recorded as a new payment.
- Cash on delivery (`cod`) is recorded with the delivery (Phase 11), not from the order screen.

### 3.2a Customers and the order form (Phase 03)
- Customers belong to a **page** (the Facebook page they contacted) and optionally a branch; they are visible to users of that page. Fields: name, phone, WhatsApp, other number, address. Phones are validated and stored in E.164 (local numbers get +94).
- Duplicate protection is page-scoped and never merges records: same phone (as phone or WhatsApp) **and** same name → the same customer (reused by the order form, 409 on the customer screen); same phone, different name → `possible_duplicate_customer` (422) until staff confirm a different person (`confirm_distinct`). Customers with orders cannot be deleted.
- Order form: CUSTOMER INFORMATION (name, phone, WhatsApp required; other number, address optional) and JOB SPECIFICATIONS (order type, due date, product / item category, gross quantity), plus the agreed unit price, delivery fee and discount used for the invoice total. Garment item details, design instructions, design attachments and overall remarks are added after payment verification (later phases).
- Order types (`order_types`, initially Single Order and Bulk Order) and product categories (`product_types`: Jersey, Arm Cut, Short, Bottom, T-Shirt) are configurable lists (`pricing.manage`), deactivated but never deleted.
- Job numbers: `{BRANCH}-{PAGE}-{YY}-{NNNNN}`, generated on the server inside the inserting transaction from `sequences` (increment-first, so concurrent requests are serialised), company-wide per year, gap-free, never reused, never updated.

### 3.3 Seven-day urgent rule (implemented in Phase 03)
- **Boundary (implemented, `UrgencyRule`):** `days` = days AFTER the reference date up to and INCLUDING the due date, all Asia/Colombo calendar dates taken from the **server** clock. `days ≥ 7` → normal (a job exactly seven days away is normal); `days < 7` → urgent. Example: created 2026-11-09 → due 2026-11-16 is normal, due 2026-11-15 is urgent. The due date must be after today.
- The reference date is the **submission date** (equal to the creation date when the form is submitted at once), or the date the due date is changed. Evaluating at submission prevents a draft created early from bypassing the rule.
- Days are **working days** (client decision): non-working weekdays (`working_calendar_days`) and company-wide or branch holidays are not counted. With no working week or holidays configured, every day counts, so the rule equals calendar days. **[ASSUMPTION on weekly days off, Q22]**
- The holiday calendar must be maintained (Sri Lankan public, bank and Poya holidays change every year). If no holidays are entered for the year of the delivery date, the system warns managers (Phase 12).
- **Approval (Phase 03 instruction, replaces the earlier shop-manager rule and answers Q25 for urgent orders):** an urgent order needs **both** a Second Admin approval (`orders.urgent.approve_admin`) and an Operation Admin approval (`orders.urgent.approve_operations`), given by **two different people**, each within their data scope. One decision per slot; any rejection (reason required) rejects the request. The job is released only after both approvals **and** a verified 30% advance.
- Both slots are notified when the request is opened; the other slot is notified when one approves.
- A rejected urgent order stays in `urgent_approval_rejected` until the due date is changed (a new evaluation opens a new request; earlier decisions are kept as history and never carry over) or the order is cancelled.
- The client never sends urgency: `is_urgent`, `urgent_status` and `status` are prohibited fields (422).

### 3.3a Job details and design task (Phase 04)
- From payment approval (`confirmed`) until the design is approved, the Page Owner (`designs.instruct`) adds: fabric type (`materials` master data), material details, design instructions, custom notes, one design-instruction attachment (JPG/PNG/WebP/PDF ≤ 25 MB) and overall remarks. The API returns none of these fields before payment approval and refuses writes (409).
- "Save and send to design" requires fabric type and design instructions, creates the design task **once**, moves the order to `design_in_progress`, and assigns the task to the **responsible Design Designer configured by the Super Admin** (`settings.manage`, Workflow settings). No person is hard-coded. Without a configured, active designer the task stays unassigned and users with `designs.assign` are notified.
- Design plan (`GET /design-tasks`): job no., branch/page, customer, category, quantity, due date, status, designer; default order **earliest due date first, urgent before normal on the same day, then oldest** — a new urgent job lands in its priority position. Designers see only their tasks.

### 3.4 Design workflow, corrections and version history (Phase 04 implementation)
- Every submission (initial, revised, final) is a new immutable `design_versions` row with `version_no`, `previous_version_id`, uploader, time, notes, files and/or a shared link. Files: preview image (JPG/PNG/WebP) and PDF/CDR sources, checked by content (PDF magic bytes; CDR = RIFF "CDR" or ZIP container with `.cdr` extension), stored privately, downloaded through 5-minute signed URLs plus the task policy (sources always as attachments).
- Shared links (Google Drive): stored only, **never fetched by the server** (no SSRF surface); https, allow-listed hosts (`DOCUMENTS_DESIGN_LINK_HOSTS`, default drive.google.com, docs.google.com), no credentials or custom port. A link does not imply a Drive integration.
- Page Owner review (`designs.record_review`, the customer's channel recorded): **request correction** with a reason → a numbered correction record (round n), task `changes_requested`, order back to `design_in_progress`; the next submission resolves it. **Correction limit** = Workflow setting (default 3, client's figure); beyond it only `designs.correction_override` holders can continue, with a recorded reason.
- **Final approval**: `designs.approve` **and** Page Owner of the order's page (`page_user.is_owner`, or company-wide page scope). Order → `design_approved`, designer notified to upload the final PDF/CDR or the approved final Drive link. The task becomes `completed` only when that final submission exists.
- Design versions are **immutable**. A correction always creates a new version (`version_no` increments per design).
- Customers **do not log in** (confirmed). Staff record every customer decision.
- Each customer review is recorded against **one specific version**, with: decision, channel (WhatsApp, Messenger, phone, in person, email), comment, evidence (screenshot of the customer's message — required for WhatsApp and Messenger approvals), the staff member who recorded it, and the time.
- Correction items are recorded individually and linked to the version that resolves them.
- Printing may only use the **approved version**. If a newer version is approved later, any print work in progress is invalidated and must restart from print preparation.
- [Q7] Limit on free corrections and correction charges.

### 3.5 Name and number list
- Each order item can have a garment list with entries: name, number, size, fit (male/female/kids), sleeve, quantity, notes.
- Numbers are stored as **strings** ("00" and "0" are different).
- Import from CSV/XLSX is validated row by row. The response lists errors per row and nothing is saved partially.
- Duplicate numbers within one list produce a **warning** **[ASSUMPTION, Q8]**.
- The list's total quantity per size must match the order item quantity before it can be locked.
- The list is **locked** when the order leaves `bulk_preparation`. Changes after locking create a new list version, need manager approval, and invalidate print preparation.

### 3.5a Name/number list (Phase 04 implementation)
- Columns are **configurable per product category** (`garment_field_definitions`: text / number / select / boolean, required, affects price, warn on duplicate, max length). Seeded: Jersey / Arm Cut / T-Shirt — Player Name, Player Number, Sleeve Type, Kids/Adult; Short / Bottom — Player Number, Logo Required, Bottom Category. Select options (`garment_options`) and sizes (category size chart) are master data; **no sizes, fabric types or bottom categories are seeded** (unknown until the client provides them).
- Available from payment approval. Rows: add one or many (bulk paste), edit, delete, search, size and price-group summaries, CSV import (all-or-nothing, per-row errors, labels or codes accepted) and CSV export (formula injection neutralised). XLSX is not supported (no spreadsheet library on shared hosting). Optimistic locking on the list.
- **Review before confirmation** — errors block: no rows, total ≠ the order's gross quantity, missing required values, stale options/sizes. Warnings must be acknowledged: duplicate values in "warn on duplicate" fields (player numbers, Q8 assumption) and identical rows.
- **Confirmation** (`garment_lists.confirm`): locks the version (user, time, total) as the confirmed snapshot, issues the invoice, and re-checks bulk eligibility — in one transaction. Locked versions are refused by the edit Action itself (409), not only by the UI.
- **Administrative correction** (`garment_lists.unlock`, reason required, audited, Page Owners notified): opens a new draft copy; the confirmed version and its invoice stay valid until the corrected list is confirmed, which supersedes the old version and voids/reissues the invoice. An existing bulk job is not duplicated.

### 3.6 Page-specific pricing and invoices (Phase 04 implementation)
- Pricing engine (`PriceQuote`): the page's active price list effective on the pricing date; rules = category × optional material × optional branch × quantity tier (on the order item's total quantity) × optional conditions on "affects price" fields (sleeve, kids/adult, bottom category …). Most specific rule wins, then the highest tier; a remaining tie with different prices is reported, never guessed. Any unpriced group blocks confirmation (409 `pricing_missing`). The client's tables are entered as rules.
- Effective dates: lists of one page never overlap; a new list from date X closes an open-ended earlier list on X−1 (optionally copying its rules). A list used by an issued invoice is frozen (409 `price_list_in_use`).
- Invoice (issued at list confirmation): number `{prefix}-{YYYY}-{NNNNNN}` per page per year; immutable line snapshots (description, quantity, unit price, rule id), price list id, pricing date. Discount = the order's discount, which can only be set by holders of `orders.discount`. Tax only when `INVOICE_TAX_PERCENT` > 0 (Q9). Total becomes the order's invoice total; the 30% advance is recalculated; remaining balance = total − verified payments. If the advance is now short, production eligibility is blocked and Page Owners, the sales owner and (released job) Operation Admins are notified.

### 3.7a Bulk job eligibility (Phase 04)
A bulk job (`bulk_jobs`, one per order, UNIQUE `order_id`) is created automatically when ALL hold: final design files/link submitted (task completed), list confirmed and locked, invoice issued for that list, verified payments ≥ 30% of the invoice, order at `design_approved`. Checked after the final design, list confirmation and each payment verification; created at most once under the order row lock. The configured responsible Bulk Designer (Workflow settings) is assigned and notified together with Operation Admins (`bulk.assign`). The order shows the readiness checklist. Assignment and the bulk workflow: §3.7b (Phase 05).

### 3.7b Bulk production management (Phase 05 implementation)
- **Creation** stays as in §3.7a (one job per order, never duplicated). The configured responsible Bulk Designer is pre-filled and notified; the job waits in `pending_assignment`.
- **Dashboards** (`GET /bulk-jobs`): the Operation Admin management view (bulk.view: every job in scope) and the Bulk Designer view (bulk.view_assigned: own jobs). Columns: job no., customer, branch/page, due date, category, quantity, material, approved design preview, final design link, confirmed list (version, total), status, assignee. Order: earliest due date, urgent first on the same day, then oldest.
- **Assignment** (`bulk.assign`, the whole order): INTERNAL → an active user with `bulk.measure` who can see the job; EXTERNAL → an active supplier (external producer). Every assignment is a history row (type, assignee/supplier, by, date, notes; reason required for any reassignment). External assignments are restricted: changing one, or switching between internal and external, needs `bulk.assignment_override` (Super Admin by default). Internal reassignment is refused while the bulk is in review or approved (same override). A mode switch supersedes the checklist instance and clears any approval. First assignment: order `design_approved → bulk_assigned`; the first work (measurement, checklist item, file, evidence) moves it to `bulk_preparation`. Assignment is refused while the verified advance is short (§3.2).
- **Measurements**: configurable fields (`bulk.configure`); Bulk Meter and Patch Meter are system fields (always required, cannot be deactivated); Collar Meter is seeded as optional for Jersey / Arm Cut / T-Shirt **[ASSUMPTION]**. Values: positive decimals within the field's range and decimal places (422 per field). Internal: assigned designer; external: `bulk.manage_external`. Frozen while in review and after completion; after completion only `bulk.correct_measurements` with a reason, and only until printing starts (order at bulk_preparation / print_preparation). Every entry and change is recorded (who, when, old → new, reason).
- **Checklist**: versioned templates per stage (internal `bulk_preparation`, external `bulk_external`) and optional category; each job gets its own instance whose items are copied from the template at assignment. Items record completed / not completed, by, at, notes. **Client confirmation** of the final checklist is recorded by the Page Owner (`bulk.approve` + owner of the page; channel, comment, optional evidence) only when every mandatory item is completed; the checklist is then locked. A bulk correction request or a list correction clears the confirmation **[ASSUMPTION: the client confirms the corrected bulk again]**.
- **Review (internal)**: the assigned designer submits review files (preview image and/or PDF/CDR, optional allow-listed link) → a new immutable version referencing the confirmed list version; Page Owners notified. The Page Owner of the page (bulk.approve) requests a correction (reason required, numbered round, designer notified, revised submission = new version, history kept) or approves (approval recorded, the approved list version is locked as the reference, designer notified). Submission is refused while the list is reopened for correction (409 `list_not_locked`).
- **List locking**: an administrative list correction while the bulk is in review or approved invalidates it (system correction, approval and list reference cleared, designer notified); after re-confirmation the job refers to the new list version. Once the bulk is completed the list cannot be corrected (409 `bulk_completed`).
- **Completion** (once; a second attempt is 409), the same mandatory data for both paths (`BulkCompletion`): verified advance still covered, current confirmed list (internal: equal to the approved reference), every required measurement, every mandatory checklist item, client confirmation. Internal additionally: approved bulk + final bulk file(s) + an https WeTransfer link (`DOCUMENTS_BULK_TRANSFER_LINK_HOSTS`, default wetransfer.com, www.wetransfer.com, we.tl; never fetched). External: recorded by `bulk.manage_external` without designer completion or WeTransfer link; evidence optional. Completion moves the order to `print_preparation` and notifies Operation Admins and Page Owners.
- **Job Card** (`GET /bulk-jobs/{id}/job-card`): read model assembled from the order, approved design preview, final design link, material, measurements and the confirmed list summary (by size, by type). Nothing is stored twice.
- **Concurrency**: every change bumps `bulk_jobs.version`; status-changing requests send `version` (409 `version_conflict`), and every Action re-checks the state under the order + job row locks.

### 3.6 Page-specific pricing and invoices (original rules)
- Each page has its own price lists (product type × quantity tier, plus option surcharges), invoice prefix, header details and logo.
- Invoice numbers are **sequential per page per year** (for example `PG1-2026-000123`). They are generated under a row lock and are never reused.
- Issued invoices are **immutable**. Corrections are made with a credit note or by voiding (with a reason) and reissuing.
- Invoice types: proforma/quotation, advance receipt, final invoice, credit note **[Q9]**.
- PDFs are generated by a queued job and stored as documents.

### 3.7 Bulk assignment and production
- An order (or part of an order item quantity) is assigned to an **internal production unit** or an **external supplier**.
- Assigned quantities must cover the ordered quantity exactly, with no over- or under-assignment.
- External assignments that send material out require an **outbound gate pass**.

### 3.8 Bulk measurements and checklists
- Measurement points (chest, length, sleeve, …) and size specs (target ± tolerance) are defined per product type.
- Out-of-tolerance measurements are flagged and need a reason to accept.
- Checklists are versioned templates per stage. Required items must pass, and `na` needs a note.

### 3.9a Printing management and sample approval (Phase 06 implementation)
- **Availability**: a printing job (`print_jobs`, UNIQUE `bulk_job_id`) is opened by `OpenPrintJob` inside the bulk completion transaction (internal final submission or external completion), status **Ready for Printing**, referencing the approved design version, the locked list version and the final bulk version. Operation Admins (`printing.manage`) are notified. An incomplete bulk job is refused (409 `bulk_not_completed`) and the dashboard joins on a completed bulk job, so it is never shown as printing-ready.
- **Dashboard** (`GET /print-jobs`, `printing.view`): job no., design preview, material, bulk meter, patch meter, collar meter where recorded, quantity, bulk files, WeTransfer link, internal/external, Job Card, confirmed list; earliest due date first, urgent first on the same day.
- **Statuses** (`PrintJobStatus`, changed only by `PrintingWorkflow::transition()` with an event row, an audit entry and the matching order transition): Ready for Printing → Sent for Printing → Sample Pending Approval ⇄ Sample Rejected; Sample Pending Approval → Sample Approved → Physical Printing In Progress → Printing Completed. Order stages: ready / sent / rejected = `print_preparation`, pending = `print_sample_review`, approved and later = `printing` (moving on to cutting is a later phase). Every change bumps `print_jobs.version` (409 `version_conflict`).
- **Send for printing** (`printing.manage`): records sender, date, printing company (active supplier, optional) and/or recipient name, notes; Page Owners notified.
- **Sample upload** (`printing.upload_sample`, after sending or after a rejection): sample image (JPG/PNG/WebP ≤ 10 MB, checked by finfo AND decoded with getimagesize as the same type with sane dimensions), optional related documents (image/PDF, up to 5), notes. Private disk, UUID paths, 5-minute signed URLs that re-check the printing policy and serve only files of that job. Each upload is a new immutable sample version; earlier samples are never overwritten. Page Owners notified.
- **Sample review** (`printing.approve_sample` AND Page Owner of the order's page): approve (reviewer, time, optional channel/comment; Operation Admins and the uploader notified; physical printing may proceed) or reject (reason REQUIRED, 422 otherwise; notified likewise; a corrected sample can then be uploaded).
- **Physical printing** (`printing.manage`): start only from Sample Approved (the approved sample row is re-checked under the lock); completion records the printed quantity and notes.
- **Document restrictions**: holders of `printing.view` can VIEW the Job Card and the name/number list. PRINTING them is a separate server-side ability (`printDocuments`, permission `printing.print_documents`) that returns 409 `document_printing_locked` until the sample is approved **[ASSUMPTION: the "explicitly approved production stage" is the Page Owner's sample approval, Q29]**. The print endpoint renders escaped HTML on the server and audits every print (`printing.document_printed`). The bulk Job Card screen no longer offers a client-side print.
- **Gate pass** (`printing.record_gate_pass`, after Printing Completed): gate pass number (unique per job), date (not in the future), optional document (image/PDF ≤ 10 MB), notes, recorded by / at. Append-only. The full gate pass workflow (approval, check-out, returns) is still the later gate pass phase.

### 3.9 Printing
- Print preparation references the approved design version **and** the locked garment list version.
- Sample approval is recorded the same way as design approval (channel, evidence, recorder).
- Physical printing records the printed quantity. Material sent to an external printer goes out on a gate pass.

### 3.10 Gate pass
- Types: outbound to supplier, return from supplier, delivery, other **[Q11]**.
- Numbered per branch per year. Flow: draft → approved (manager) → checked out (gate keeper) → returned/closed.
- Lines record the quantity out and the quantity returned. Differences must be explained before closing.

### 3.11 Cutting, sewing and quality control
- Cutting records planned and cut quantity per batch.
- Sewing records quantity received from cutting and quantity sewn, per batch. Sewing can be internal (production unit) or external (supplier, with a gate pass) **[Q21]**.
- QC inspections record inspected, passed and failed quantity, with defects by type. A defect can reference a garment list entry (for example a misspelled name).
- Rework sends the order back to printing or cutting for the affected entries only **[Q10]**.

### 3.12 Delivery and completion
- Methods: customer pickup, courier, own vehicle.
- Dispatch needs a delivery record, and a gate pass when goods leave the premises.
- Delivery needs proof: recipient name, time, and an optional photo or signature.
- The balance does **not** have to be paid before dispatch. **Cash on delivery** is allowed (confirmed).
- For COD, the delivery record stores the amount to collect (= outstanding balance at dispatch). The collected cash is recorded as a payment with method `cod`, which Accounts then verifies. Courier COD remittances may arrive later and in batches **[Q23]**.
- Completion needs a verified balance of 0, or an approved write-off.

### 3.13 Notifications (minimum set)

| Event | Recipients |
|---|---|
| New order submitted (Phase 03) | Page Owners of the page, payment verifiers in scope |
| Payment recorded (needs verification) | Payment verifiers (Second Admins) in scope, never the recorder |
| Payment verified or rejected | Recording staff member, Page Owners |
| Advance below 30% after verification (Phase 03) | Page Owners, sales owner; the Operation Admin only if they already approved the order as urgent |
| Urgent order needs approval | Holders of each urgent approval slot in scope (Second Admin, Operation Admin) |
| Urgent order approved / rejected | Order creator, sales owner, Page Owners |
| Design assigned / correction requested / approved (final files due) (Phase 04) | Assigned designer |
| Design unassigned (no responsible designer configured) | Users with `designs.assign` in scope |
| Design version submitted / final files received | Page Owners of the page |
| Invoice issued (list confirmed) | Page Owners, payment verifiers in scope |
| List reopened by administrative correction | Page Owners |
| Bulk job ready | Responsible Bulk Designer, Operation Admins (`bulk.assign`) in scope |
| Bulk assigned internally (Phase 05) | The assigned Bulk Designer |
| Bulk assigned externally (Phase 05) | External bulk recorders (`bulk.manage_external`) in scope, Page Owners |
| Bulk submitted for review (Phase 05) | Page Owners of the page |
| Bulk correction requested / approved (Phase 05) | The assigned Bulk Designer |
| Bulk completed (Phase 05) | Operation Admins (`bulk.assign`) in scope, Page Owners |
| Job ready for printing (Phase 06) | Operation Admins (`printing.manage`) in scope |
| Sent for printing / printing sample uploaded (Phase 06) | Page Owners of the page |
| Printing sample approved / rejected (Phase 06) | Operation Admins (`printing.manage`) in scope, the sample uploader |
| Order confirmed | Design lead |
| Design version submitted | Sales owner of the order |
| Design changes requested / approved | Assigned designer / production manager |
| Print sample awaiting decision | Sales owner |
| Gate pass awaiting approval / overdue return | Branch manager |
| QC failed | Production manager |
| Order ready for delivery | Sales owner, delivery coordinator |
| Delivery date at risk (scheduled daily check) | Branch manager |

Channels: in-app (database, always on). The UI polls for new notifications because shared hosting cannot run a WebSocket server (Reverb). Realtime push is added only if the hosting changes. Email, SMS and WhatsApp depend on [Q12].

### 3.14 Audit history
Every state transition, payment action, approval, price list change, invoice action, gate pass action, list lock/unlock, permission change and login is written to the append-only audit log with actor, time, IP, before/after values and a request ID.

## 4. Out of scope until confirmed
Customer self-service portal (confirmed not needed), supplier portal, accounting ledger and payables, inventory of fabric and ink, payroll, courier API integrations, multi-currency.

## 5. Acceptance

Each rule in section 3 needs at least one automated backend test, and each transition in 2.2 needs a positive test and a negative test (wrong state, missing permission, other branch).

## 6. Questions requiring client confirmation

### 6.1 Answered (2026-11-08)

| # | Answer |
|---|---|
| Q1 (part) | A page is a **Facebook page**. |
| Q2 (part) | The 30% is calculated on the total **including** delivery and tax. |
| Q3 | **Working days**; urgent orders are approved by **shop managers**. |
| Q4 | Customers **do not log in**; staff record approvals. |
| Q5 | Balance is **not** required before dispatch; **cash on delivery** is used. |
| Q6 (part) | **Sewing** is a production stage. |
| Q13 | **One company with branches** (not multi-tenant). |
| Q15 | **Shared hosting** (see ARCHITECTURE.md §5 for what this limits). |

### 6.2 Open

Still open: rest of Q1 (page ↔ branch, page-limited staff), rest of Q2 (exceptions below 30%; top-up rule), Q7–Q12, Q14, Q16–Q20, and the new questions below.

| # | Question |
|---|---|
| Q21 | Where exactly is sewing — after cutting and before QC? Is sewing done in-house, by outside tailors, or both? |
| Q22 | Which weekdays are working days (Mon–Fri or Mon–Sat)? Are Poya days and all public holidays non-working? Does the order date count as day 1? |
| Q23 | For COD, who collects — your own delivery staff, a courier company, or both? If a courier, do they send the money later in one bulk transfer? |
| Q24 | Shared hosting details: provider name, PHP version, SSH access, cron jobs, Node.js app support, and whether Redis is offered. Would you consider a small VPS (about the same price) if the shared plan cannot run the system? |
| Q25 | The new role list has no Branch/Shop Manager. Who approves urgent orders — the Page Owner (current default), a Super Admin, or a new Shop Manager role? Who approves gate passes and late cancellations? |
| Q26 | Is a Page Owner limited to their pages across **all** branches (current default), or also to certain branches? Is Second Admin page-scoped (current default) or company-wide? |
| Q27 | Do External Bulk Designers belong to a supplier (so they could be limited to that supplier's jobs), or are they individual freelancers? |
| Q28 | Pricing configuration per page: besides currency, default advance % and default delivery fee, what else differs per page (tax, discounts)? |
| Q29 | Printing the Job Card and the name/number list: from which approved stage may they be printed (current default: once the Page Owner approves the printing sample)? Must the printed quantity equal the ordered quantity? |

### 6.3 Original question list
| Q1 | What exactly is a **"Page"**? (A Facebook/Instagram page, a brand, a shop?) Can a page sell for several branches? Should sales staff be limited to certain pages? |
| Q2 | Is the 30% advance calculated on the total **including** delivery and tax? Are exceptions below 30% ever allowed (schools, corporate customers), and who approves them? If the total rises after payment, must the customer top up before production? |
| Q3 | Seven-day rule: calendar or working days? Counted from order creation or advance verification? Who may approve? Is there an urgent surcharge? |
| Q4 | Do customers **log in** to approve designs and samples, or does staff record approvals received by WhatsApp or phone? |
| Q5 | Must the full balance be paid before dispatch? Are cash on delivery or credit terms allowed? |
| Q6 | Where does **sewing/stitching** happen, and should it be tracked as a stage? Is "bulk production" the sewing step? |
| Q7 | How many free design corrections are included? Are extra corrections charged? |
| Q8 | Are duplicate player numbers in one list an error or allowed? What size charts are used? |
| Q9 | Is the business VAT/SSCL registered? Which invoice types are needed (quotation, advance receipt, final, credit note)? Required invoice number format? |
| Q10 | QC failure: who decides reprint vs repair vs scrap, and is the cost tracked? |
| Q11 | Which movements need a gate pass, and who approves and checks them? |
| Q12 | Notification channels: email, SMS (which provider), WhatsApp? |
| Q13 | Is this for one company with several branches, or will it be sold to several companies (multi-tenant)? |
| Q14 | Can one order contain several product types? Can one order be split across internal and external production? |
| Q15 | Hosting: will a Linux VPS be available? (Needed for Redis, queues and realtime.) |
| Q16 | Is there existing data (customers, open orders) to migrate? In what format? |
| Q17 | Interface languages: English only, or also Sinhala/Tamil? |
| Q18 | Maximum design file size, and how long must design files be kept? |
| Q19 | Are supplier payments and costs to be tracked in this system? |
| Q20 | Which reports does management need first? |
