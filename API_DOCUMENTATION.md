# API Documentation

Reference for the Garment ERP REST API (`/api/v1`). Conventions (envelope, pagination, money, dates, versioning) are in API_STANDARDS.md; request and response schemas for the main resources are in `docs/openapi.yaml`. This page lists every endpoint and the error codes clients must handle.

## 1. Basics

| Item | Value |
|---|---|
| Base URL | `https://garment-erp.codeplaystudios.com/api/v1` (same origin as the frontend) |
| Auth | Sanctum SPA session cookie. `GET /sanctum/csrf-cookie`, then send `X-XSRF-TOKEN` on every POST/PUT/PATCH/DELETE. Login only from a configured stateful origin |
| Content | JSON (`Accept: application/json`). Uploads: `multipart/form-data` |
| Success | `{ "data": …, "meta"?: …, "links"?: … }`; detail resources include an `abilities` object (UI hints only — the server re-checks everything) |
| Error | `{ "message", "code", "errors"?, "request_id" }`; `X-Request-Id` header on every response |
| Lists | `?page=&per_page=` (default 25, max 100), `?filter[...]=`, `?sort=-created_at` (whitelisted per endpoint) |
| Money | integer minor units, `{ "amount_minor": 123450, "formatted": "LKR 1,234.50" }` |
| Concurrency | state-changing workflow calls send the record's `version`; a stale version returns 409 `version_conflict` |
| Idempotency | `Idempotency-Key: <uuid>` on `POST /orders/{order}/payments` |
| Files | download links are 5-minute signed URLs returned by the API; the download also re-checks the record's policy |

## 2. Status codes

| Status | Meaning |
|---|---|
| 401 | not signed in, session expired (12 h absolute / 2 h idle) or revoked |
| 403 | signed in, missing permission; also `separation_of_duties`, `not_page_owner`, `privilege_escalation` |
| 404 | not found **or outside your branches/pages** (existence is never revealed) |
| 409 | the record's state does not allow the action, or a version conflict |
| 413 | body too large |
| 422 | validation failed (`errors` per field) |
| 429 | rate limit (login 5/min per email+IP plus exponential lockout; API 120/min per user; uploads 20/min; exports and reports 10/min) |
| 503 | `GET /health` only: a dependency is failing or the scheduler heartbeat is stale |

## 3. Domain error codes (`code`)

| Area | Codes |
|---|---|
| Generic | `validation_failed`, `forbidden`, `not_found`, `invalid_state`, `invalid_transition`, `version_conflict`, `no_change` |
| Identity | `stateful_origin_required`, `login_locked`, `account_inactive`, `invalid_reset_token`, `privilege_escalation`, `own_account`, `role_locked`, `role_system`, `role_in_use` |
| Orders and payments | `separation_of_duties`, `already_decided`, `idempotency_conflict`, `advance_short`, `duplicate_customer`, `possible_duplicate_customer`, `customer_has_orders`, `organization_missing` |
| Design | `not_page_owner`, `not_assignee`, `design_task_exists`, `correction_limit_reached` |
| Lists, pricing, invoices | `list_locked`, `list_invalid`, `list_warnings`, `list_missing`, `list_not_locked`, `list_changed`, `pricing_missing`, `price_list_in_use`, `import_unreadable`, `system_field` |
| Bulk | `bulk_incomplete`, `bulk_completed`, `bulk_not_completed`, `external_assignment_restricted`, `already_assigned`, `checklist_missing`, `checklist_incomplete`, `checklist_confirmed`, `checklist_already_confirmed`, `order_not_in_bulk_stage`, `production_finalized` |
| Printing | `document_printing_locked`, `sample_not_approved`, `printing_not_completed`, `duplicate_gate_pass`, `order_not_in_printing_stage` |
| Cutting, sewing | `cutting_incomplete`, `invalid_totals`, `order_not_in_cutting_stage`, `order_not_in_sewing_stage` |
| Delivery, completion | `not_ready_for_delivery`, `not_dispatched`, `job_incomplete` (errors grouped by `production`, `checks`, `issues`, `delivery`, `documents`, `balance`), `already_completed` |

## 4. Endpoints

All routes except `health`, `auth/login`, `auth/forgot-password` and `auth/reset-password` require a session. Every authenticated route except the caller's own account and notification routes runs a policy check (route coverage test: `tests/Feature/Authorization/RouteAuthorizationTest.php`). Generated from `php artisan route:list` (Phase 09, 184 endpoints).

| Method | Path | Handler | Extra middleware |
|---|---|---|---|
| POST | `/auth/forgot-password` | `Identity\AuthController@forgotPassword` | throttle password-reset |
| POST | `/auth/login` | `Identity\AuthController@login` | throttle login |
| POST | `/auth/logout` | `Identity\AuthController@logout` | |
| GET | `/auth/me` | `Identity\AuthController@me` | |
| PUT | `/auth/password` | `Identity\AuthController@changePassword` | throttle sensitive |
| PATCH | `/auth/profile` | `Identity\AuthController@updateProfile` | throttle sensitive |
| POST | `/auth/reset-password` | `Identity\AuthController@resetPassword` | throttle password-reset |
| GET | `/branches` | `Organizations\BranchController@index` | |
| POST | `/branches` | `Organizations\BranchController@store` | |
| GET | `/branches/{branch}` | `Organizations\BranchController@show` | |
| PATCH | `/branches/{branch}` | `Organizations\BranchController@update` | |
| GET | `/bulk-jobs` | `BulkProduction\BulkJobController@index` | |
| GET | `/bulk-jobs/assignees` | `BulkProduction\BulkJobController@assignees` | |
| GET | `/bulk-jobs/{job}` | `BulkProduction\BulkJobController@show` | |
| POST | `/bulk-jobs/{job}/approve` | `BulkProduction\BulkWorkController@approve` | |
| POST | `/bulk-jobs/{job}/assignment` | `BulkProduction\BulkJobController@assign` | |
| POST | `/bulk-jobs/{job}/checklist/client-confirmation` | `BulkProduction\BulkWorkController@confirmChecklist` | throttle uploads |
| PATCH | `/bulk-jobs/{job}/checklist/items/{item}` | `BulkProduction\BulkWorkController@checklistItem` | |
| POST | `/bulk-jobs/{job}/corrections` | `BulkProduction\BulkWorkController@requestCorrection` | |
| GET | `/bulk-jobs/{job}/documents/{document}` | `BulkProduction\BulkJobController@download` | signed URL |
| POST | `/bulk-jobs/{job}/external/complete` | `BulkProduction\BulkWorkController@completeExternal` | |
| POST | `/bulk-jobs/{job}/external/evidence` | `BulkProduction\BulkWorkController@addEvidence` | throttle uploads |
| POST | `/bulk-jobs/{job}/final` | `BulkProduction\BulkWorkController@submitFinal` | throttle uploads |
| GET | `/bulk-jobs/{job}/garment-list` | `BulkProduction\BulkJobController@garmentList` | |
| GET | `/bulk-jobs/{job}/garment-list/export` | `BulkProduction\BulkJobController@exportGarmentList` | throttle exports |
| GET | `/bulk-jobs/{job}/job-card` | `BulkProduction\BulkJobController@jobCard` | |
| PUT | `/bulk-jobs/{job}/measurements` | `BulkProduction\BulkWorkController@measurements` | |
| POST | `/bulk-jobs/{job}/measurements/corrections` | `BulkProduction\BulkWorkController@correctMeasurements` | |
| POST | `/bulk-jobs/{job}/versions` | `BulkProduction\BulkWorkController@submitForReview` | throttle uploads |
| GET | `/bulk-setup` | `BulkProduction\BulkSetupController@index` | |
| POST | `/bulk-setup/checklist-templates` | `BulkProduction\BulkSetupController@storeTemplate` | |
| PATCH | `/bulk-setup/checklist-templates/{template}` | `BulkProduction\BulkSetupController@updateTemplate` | |
| POST | `/bulk-setup/measurement-fields` | `BulkProduction\BulkSetupController@storeField` | |
| PATCH | `/bulk-setup/measurement-fields/{field}` | `BulkProduction\BulkSetupController@updateField` | |
| POST | `/bulk-setup/suppliers` | `BulkProduction\BulkSetupController@storeSupplier` | |
| PATCH | `/bulk-setup/suppliers/{supplier}` | `BulkProduction\BulkSetupController@updateSupplier` | |
| GET | `/customers` | `Customers\CustomerController@index` | |
| POST | `/customers` | `Customers\CustomerController@store` | |
| GET | `/customers/{customer}` | `Customers\CustomerController@show` | |
| PATCH | `/customers/{customer}` | `Customers\CustomerController@update` | |
| DELETE | `/customers/{customer}` | `Customers\CustomerController@destroy` | |
| GET | `/cutting-jobs` | `Cutting\CuttingJobController@index` | |
| GET | `/cutting-jobs/{job}` | `Cutting\CuttingJobController@show` | |
| POST | `/cutting-jobs/{job}/assignment` | `Cutting\CuttingWorkController@assign` | |
| POST | `/cutting-jobs/{job}/checklist/client-confirmation` | `Cutting\CuttingWorkController@confirmChecklist` | throttle uploads |
| PATCH | `/cutting-jobs/{job}/checklist/items/{item}` | `Cutting\CuttingWorkController@checklistItem` | |
| POST | `/cutting-jobs/{job}/complete` | `Cutting\CuttingWorkController@complete` | |
| GET | `/cutting-jobs/{job}/cutters` | `Cutting\CuttingJobController@cutters` | |
| GET | `/cutting-jobs/{job}/files/{document}` | `Cutting\CuttingJobController@download` | signed URL |
| POST | `/cutting-jobs/{job}/issues` | `Cutting\CuttingWorkController@reportIssue` | throttle uploads |
| GET | `/cutting-jobs/{job}/job-card` | `Cutting\CuttingJobController@jobCard` | |
| GET | `/cutting-jobs/{job}/name-list` | `Cutting\CuttingJobController@nameList` | |
| PATCH | `/cutting-jobs/{job}/quality-checks/{item}` | `Cutting\CuttingWorkController@qualityCheck` | |
| PUT | `/cutting-jobs/{job}/quantities` | `Cutting\CuttingWorkController@quantities` | |
| GET | `/cutting-setup` | `Cutting\CuttingSetupController@index` | |
| POST | `/cutting-setup/checklist-templates` | `Cutting\CuttingSetupController@storeTemplate` | |
| PATCH | `/cutting-setup/checklist-templates/{template}` | `Cutting\CuttingSetupController@updateTemplate` | |
| GET | `/dashboard` | `Reporting\ReportController@dashboard` | |
| GET | `/deliveries` | `Delivery\DeliveryController@index` | |
| GET | `/deliveries/{delivery}` | `Delivery\DeliveryController@show` | |
| POST | `/deliveries/{delivery}/complete` | `Delivery\DeliveryController@complete` | |
| POST | `/deliveries/{delivery}/deliver` | `Delivery\DeliveryController@deliver` | |
| POST | `/deliveries/{delivery}/dispatch` | `Delivery\DeliveryController@dispatch` | |
| POST | `/deliveries/{delivery}/documents` | `Delivery\DeliveryController@storeDocument` | throttle uploads |
| GET | `/deliveries/{delivery}/documents/{document}` | `Delivery\DeliveryController@download` | signed URL |
| GET | `/design-tasks` | `Design\DesignTaskController@index` | |
| GET | `/design-tasks/designers` | `Design\DesignTaskController@designers` | |
| GET | `/design-tasks/{task}` | `Design\DesignTaskController@show` | |
| POST | `/design-tasks/{task}/approve` | `Design\DesignTaskController@approve` | |
| POST | `/design-tasks/{task}/assign` | `Design\DesignTaskController@assign` | |
| POST | `/design-tasks/{task}/corrections` | `Design\DesignTaskController@requestCorrection` | |
| GET | `/design-tasks/{task}/files/{file}` | `Design\DesignTaskController@download` | signed URL |
| POST | `/design-tasks/{task}/final` | `Design\DesignTaskController@submitFinal` | throttle uploads |
| POST | `/design-tasks/{task}/versions` | `Design\DesignTaskController@submitVersion` | throttle uploads |
| POST | `/garment-lists/{list}/confirm` | `Garments\GarmentListController@confirm` | |
| POST | `/garment-lists/{list}/corrections` | `Garments\GarmentListController@correct` | |
| GET | `/garment-lists/{list}/export` | `Garments\GarmentListController@export` | throttle exports |
| POST | `/garment-lists/{list}/import` | `Garments\GarmentListController@import` | throttle uploads |
| POST | `/garment-lists/{list}/rows` | `Garments\GarmentListController@addRows` | |
| PATCH | `/garment-lists/{list}/rows/{item}` | `Garments\GarmentListController@updateRow` | |
| DELETE | `/garment-lists/{list}/rows/{item}` | `Garments\GarmentListController@deleteRow` | |
| GET | `/garment-setup` | `Garments\GarmentSetupController@index` | |
| PATCH | `/garment-setup/categories/{category}` | `Garments\GarmentSetupController@setCategoryChart` | |
| POST | `/garment-setup/fields` | `Garments\GarmentSetupController@storeField` | |
| PATCH | `/garment-setup/fields/{field}` | `Garments\GarmentSetupController@updateField` | |
| POST | `/garment-setup/option-sets` | `Garments\GarmentSetupController@storeOptionSet` | |
| POST | `/garment-setup/option-sets/{set}/options` | `Garments\GarmentSetupController@storeOption` | |
| PATCH | `/garment-setup/options/{option}` | `Garments\GarmentSetupController@updateOption` | |
| POST | `/garment-setup/size-charts` | `Garments\GarmentSetupController@storeSizeChart` | |
| POST | `/garment-setup/size-charts/{chart}/sizes` | `Garments\GarmentSetupController@storeSize` | |
| PATCH | `/garment-setup/sizes/{size}` | `Garments\GarmentSetupController@updateSize` | |
| GET | `/health` | `App\HealthController` |  |
| GET | `/invoices/{invoice}` | `Invoices\InvoiceController@show` | |
| GET | `/materials` | `Pricing\MaterialController@index` | |
| POST | `/materials` | `Pricing\MaterialController@store` | |
| PATCH | `/materials/{id}` | `Pricing\MaterialController@update` | |
| GET | `/notifications` | `Notifications\NotificationController@index` | |
| POST | `/notifications/mark-all-read` | `Notifications\NotificationController@markAllRead` | |
| GET | `/notifications/poll` | `Notifications\NotificationController@poll` | |
| GET | `/notifications/unread-count` | `Notifications\NotificationController@unreadCount` | |
| POST | `/notifications/{notification}/read` | `Notifications\NotificationController@markRead` | |
| POST | `/notifications/{notification}/unread` | `Notifications\NotificationController@markUnread` | |
| GET | `/order-types` | `Orders\OrderTypeController@index` | |
| POST | `/order-types` | `Orders\OrderTypeController@store` | |
| PATCH | `/order-types/{id}` | `Orders\OrderTypeController@update` | |
| GET | `/orders` | `Orders\OrderController@index` | |
| POST | `/orders` | `Orders\OrderController@store` | |
| GET | `/orders/{order}` | `Orders\OrderController@show` | |
| PATCH | `/orders/{order}` | `Orders\OrderController@update` | |
| GET | `/orders/{order}/garment-list` | `Garments\GarmentListController@show` | |
| GET | `/orders/{order}/invoices` | `Invoices\InvoiceController@forOrder` | |
| POST | `/orders/{order}/job-details` | `Orders\JobDetailsController@update` | throttle uploads |
| GET | `/orders/{order}/job-details/attachment` | `Orders\JobDetailsController@attachment` | signed URL |
| POST | `/orders/{order}/job-details/submit` | `Orders\JobDetailsController@submit` | |
| GET | `/orders/{order}/payments` | `Payments\PaymentController@forOrder` | |
| POST | `/orders/{order}/payments` | `Payments\PaymentController@store` | throttle uploads |
| POST | `/orders/{order}/submit` | `Orders\OrderController@submit` | |
| POST | `/orders/{order}/urgent-decisions` | `Orders\OrderController@decideUrgent` | |
| GET | `/pages` | `Organizations\PageController@index` | |
| POST | `/pages` | `Organizations\PageController@store` | |
| GET | `/pages/{page}` | `Organizations\PageController@show` | |
| PATCH | `/pages/{page}` | `Organizations\PageController@update` | |
| GET | `/pages/{page}/members` | `Identity\PageMemberController@index` | |
| PUT | `/pages/{page}/members` | `Identity\PageMemberController@update` | |
| GET | `/payments` | `Payments\PaymentController@index` | |
| GET | `/payments/{payment}` | `Payments\PaymentController@show` | |
| GET | `/payments/{payment}/evidence` | `Payments\PaymentController@evidence` | signed URL |
| POST | `/payments/{payment}/reject` | `Payments\PaymentController@reject` | |
| POST | `/payments/{payment}/verify` | `Payments\PaymentController@verify` | |
| GET | `/permissions` | `Identity\RoleController@permissions` | |
| GET | `/price-lists` | `Pricing\PriceListController@index` | |
| POST | `/price-lists` | `Pricing\PriceListController@store` | |
| GET | `/price-lists/{priceList}` | `Pricing\PriceListController@show` | |
| PATCH | `/price-lists/{priceList}` | `Pricing\PriceListController@update` | |
| POST | `/price-lists/{priceList}/rules` | `Pricing\PriceListController@storeRule` | |
| PATCH | `/price-lists/{priceList}/rules/{rule}` | `Pricing\PriceListController@updateRule` | |
| DELETE | `/price-lists/{priceList}/rules/{rule}` | `Pricing\PriceListController@destroyRule` | |
| GET | `/print-jobs` | `Printing\PrintJobController@index` | |
| GET | `/print-jobs/recipients` | `Printing\PrintJobController@recipients` | |
| GET | `/print-jobs/{job}` | `Printing\PrintJobController@show` | |
| GET | `/print-jobs/{job}/documents/{document}/print` | `Printing\PrintJobController@print` | throttle exports |
| GET | `/print-jobs/{job}/files/{document}` | `Printing\PrintJobController@download` | signed URL |
| POST | `/print-jobs/{job}/gate-passes` | `Printing\PrintWorkController@recordGatePass` | throttle uploads |
| GET | `/print-jobs/{job}/job-card` | `Printing\PrintJobController@jobCard` | |
| GET | `/print-jobs/{job}/name-list` | `Printing\PrintJobController@nameList` | |
| POST | `/print-jobs/{job}/physical-printing/complete` | `Printing\PrintWorkController@completePrinting` | |
| POST | `/print-jobs/{job}/physical-printing/start` | `Printing\PrintWorkController@startPrinting` | |
| POST | `/print-jobs/{job}/samples` | `Printing\PrintWorkController@uploadSample` | throttle uploads |
| POST | `/print-jobs/{job}/samples/approve` | `Printing\PrintWorkController@approveSample` | |
| POST | `/print-jobs/{job}/samples/reject` | `Printing\PrintWorkController@rejectSample` | |
| POST | `/print-jobs/{job}/send` | `Printing\PrintWorkController@send` | |
| GET | `/product-categories` | `Pricing\ProductCategoryController@index` | |
| POST | `/product-categories` | `Pricing\ProductCategoryController@store` | |
| PATCH | `/product-categories/{id}` | `Pricing\ProductCategoryController@update` | |
| GET | `/production-issues` | `BulkProduction\ProductionIssueController@index` | |
| POST | `/production-issues` | `BulkProduction\ProductionIssueController@store` | throttle uploads |
| GET | `/production-issues/{issue}` | `BulkProduction\ProductionIssueController@show` | |
| PATCH | `/production-issues/{issue}` | `BulkProduction\ProductionIssueController@update` | |
| GET | `/production-issues/{issue}/assignees` | `BulkProduction\ProductionIssueController@assignees` | |
| GET | `/production-issues/{issue}/image` | `BulkProduction\ProductionIssueController@image` | signed URL |
| POST | `/production-issues/{issue}/transitions` | `BulkProduction\ProductionIssueController@transition` | |
| GET | `/reports` | `Reporting\ReportController@catalogue` | |
| GET | `/reports/{report}` | `Reporting\ReportController@show` | throttle exports |
| GET | `/roles` | `Identity\RoleController@index` | |
| POST | `/roles` | `Identity\RoleController@store` | |
| GET | `/roles/{role}` | `Identity\RoleController@show` | |
| PATCH | `/roles/{role}` | `Identity\RoleController@update` | |
| DELETE | `/roles/{role}` | `Identity\RoleController@destroy` | |
| GET | `/settings/workflow` | `Organizations\WorkflowSettingsController@show` | |
| PUT | `/settings/workflow` | `Organizations\WorkflowSettingsController@update` | |
| GET | `/sewing-jobs` | `Sewing\SewingJobController@index` | |
| GET | `/sewing-jobs/{job}` | `Sewing\SewingJobController@show` | |
| POST | `/sewing-jobs/{job}/complete` | `Sewing\SewingJobController@complete` | |
| POST | `/sewing-jobs/{job}/start` | `Sewing\SewingJobController@start` | |
| GET | `/users` | `Identity\UserController@index` | |
| POST | `/users` | `Identity\UserController@store` | throttle sensitive |
| GET | `/users/{user}` | `Identity\UserController@show` | |
| PATCH | `/users/{user}` | `Identity\UserController@update` | |
| GET | `/users/{user}/activity` | `Identity\UserController@activity` | |
| PUT | `/users/{user}/assignments` | `Identity\UserController@assignments` | |
| PUT | `/users/{user}/password` | `Identity\UserController@setPassword` | throttle sensitive |
| POST | `/users/{user}/password-reset` | `Identity\UserController@sendPasswordReset` | throttle sensitive |
| PUT | `/users/{user}/roles` | `Identity\UserController@roles` | |
| PUT | `/users/{user}/status` | `Identity\UserController@status` | |
