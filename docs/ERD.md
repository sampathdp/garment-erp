# Entity Relationship Diagram

Generated from the Phase 01 migrations (`backend/database/migrations`). Columns are abbreviated to keys and the most important attributes; see the migrations for the full definitions. Diagrams render on GitHub and in most Markdown viewers that support Mermaid.

`branch_id` on transactional tables is copied from the order when the row is created and never changes. It exists so lists can be branch-scoped without joins (DATABASE_DESIGN.md §2).

## 1. Organization, identity and scoping

```mermaid
erDiagram
    organizations ||--o{ branches : has
    organizations ||--o{ pages : has
    organizations ||--o{ working_calendar_days : defines
    branches ||--o{ holidays : "closes on (null = company-wide)"
    users ||--o{ branch_user : ""
    branches ||--o{ branch_user : ""
    users ||--o{ page_user : ""
    pages ||--o{ page_user : ""
    users ||--o{ model_has_roles : ""
    roles ||--o{ model_has_roles : ""
    roles ||--o{ role_has_permissions : ""
    permissions ||--o{ role_has_permissions : ""
    documents ||--o| pages : "logo"

    organizations { bigint id PK
        string name
        char default_currency }
    branches { bigint id PK
        bigint organization_id FK
        string code UK
        bool is_active }
    pages { bigint id PK
        bigint organization_id FK
        string code UK
        string invoice_prefix UK
        string platform "facebook" }
    users { bigint id PK
        string email UK
        bool is_active
        timestamp deleted_at }
    branch_user { bigint branch_id PK
        bigint user_id PK }
    page_user { bigint page_id PK
        bigint user_id PK }
    working_calendar_days { bigint organization_id FK
        tinyint iso_weekday
        bool is_working_day }
    holidays { bigint branch_id FK "nullable"
        date holiday_date }
```

## 2. Orders, pricing, payments and invoices

```mermaid
erDiagram
    customers ||--o{ orders : places
    branches ||--o{ orders : produces
    pages ||--o{ orders : sells
    users ||--o{ orders : "sales owner"
    orders ||--|{ order_items : contains
    product_types ||--o{ order_items : "category"
    materials ||--o{ order_items : "material"
    order_items ||--o{ order_item_options : ""
    product_options ||--o{ order_item_options : ""
    orders ||--o{ order_status_histories : ""
    orders ||--o{ urgent_approvals : ""
    pages ||--o{ price_lists : ""
    price_lists ||--o{ price_list_items : "pricing rules"
    price_lists ||--o{ price_list_option_prices : ""
    size_charts ||--o{ sizes : ""
    size_charts ||--o{ product_types : ""
    product_types ||--o{ product_options : ""
    orders ||--o{ payments : ""
    payments ||--o{ refunds : ""
    documents ||--o| payments : "slip"
    orders ||--o{ invoices : ""
    pages ||--o{ invoices : "numbering + identity"
    invoices ||--|{ invoice_items : ""
    invoices ||--o| invoices : "credit note for"

    orders { bigint id PK
        string order_no UK "Job No."
        bigint branch_id FK
        bigint page_id FK
        bigint customer_id FK
        string status "OrderStatus"
        string payment_status "OrderPaymentStatus"
        date required_delivery_date
        bigint total_minor
        tinyint advance_percent
        bigint verified_paid_minor
        int version }
    order_items { bigint id PK
        bigint order_id FK
        bigint product_type_id FK
        bigint material_id FK
        int quantity
        bigint unit_price_minor "snapshot" }
    payments { bigint id PK
        bigint order_id FK
        bigint branch_id FK
        bigint amount_minor
        string method
        string status
        bigint recorded_by FK
        bigint verified_by FK "<> recorded_by"
        uuid idempotency_key UK }
    invoices { bigint id PK
        string invoice_no UK
        bigint page_id FK
        bigint order_id FK
        string type
        string status }
```

## 3. Design and garment lists

```mermaid
erDiagram
    orders ||--o{ design_tasks : ""
    order_items ||--|| design_tasks : "one per item"
    design_tasks ||--|{ design_versions : "immutable versions"
    design_tasks |o--o| design_versions : "current / approved"
    design_versions ||--o{ design_version_files : ""
    documents ||--o{ design_version_files : ""
    design_versions ||--o{ design_reviews : "customer decisions (staff-recorded)"
    design_reviews ||--o{ design_correction_requests : ""
    design_correction_requests }o--o| design_versions : "resolved in"
    order_items ||--|| garment_lists : "one per item"
    garment_lists ||--|{ garment_list_versions : ""
    garment_list_versions ||--|{ garment_list_items : "name / number / size"
    sizes ||--o{ garment_list_items : ""

    design_versions { bigint id PK
        bigint design_task_id FK
        smallint version_no "UK with task"
        timestamp submitted_at }
    design_reviews { bigint id PK
        bigint design_version_id FK
        string decision
        string channel "whatsapp, messenger, ..."
        bigint evidence_document_id FK }
    garment_list_versions { bigint id PK
        bigint garment_list_id FK
        smallint version_no
        string status "draft, locked, superseded" }
    garment_list_items { bigint id PK
        bigint garment_list_version_id FK
        int line_no
        string player_name
        string number "string: 00 != 0"
        bigint size_id FK }
```

## 4. Production, printing, gate passes, QC and delivery

```mermaid
erDiagram
    orders ||--o{ bulk_assignments : ""
    production_units ||--o{ bulk_assignments : "internal"
    suppliers ||--o{ bulk_assignments : "external"
    bulk_assignments ||--o{ bulk_measurements : ""
    bulk_assignments ||--o{ bulk_assignment_documents : ""
    measurement_points ||--o{ size_specs : ""
    checklist_templates ||--|{ checklist_template_items : ""
    checklist_templates ||--o{ checklist_runs : ""
    orders ||--o{ checklist_runs : ""
    checklist_runs ||--|{ checklist_run_items : ""
    orders ||--o{ production_issues : "corrections / problems"
    orders ||--o{ print_jobs : ""
    design_versions ||--o{ print_jobs : "approved version"
    garment_list_versions ||--o{ print_jobs : "locked version"
    print_jobs ||--o{ print_samples : "sample approval"
    print_jobs ||--o{ cutting_jobs : ""
    cutting_jobs ||--o{ sewing_jobs : ""
    gate_passes ||--o{ print_jobs : "external"
    gate_passes ||--o{ cutting_jobs : "external"
    gate_passes ||--o{ sewing_jobs : "external"
    gate_passes ||--|{ gate_pass_lines : ""
    suppliers ||--o{ gate_passes : ""
    orders ||--o{ qc_inspections : ""
    qc_inspections ||--o{ qc_defects : ""
    defect_types ||--o{ qc_defects : ""
    garment_list_items ||--o{ qc_defects : "affected garment"
    orders ||--o{ deliveries : ""
    deliveries ||--o{ gate_passes : "dispatch"
    deliveries ||--o{ payments : "COD collections"

    bulk_assignments { bigint id PK
        bigint order_item_id FK
        string mode "CHECK: unit xor supplier"
        int quantity }
    gate_passes { bigint id PK
        string gate_pass_no UK
        bigint branch_id FK
        string type
        string status }
    deliveries { bigint id PK
        bigint order_id FK
        string method
        bigint cod_amount_minor
        string cod_status }
    qc_inspections { bigint id PK
        string stage
        int quantity_inspected "= passed + failed" }
```

## 5. Cross-cutting

```mermaid
erDiagram
    users ||--o{ audit_logs : "actor"
    users ||--o{ notifications : "notifiable (morph)"
    users ||--o{ notification_preferences : ""
    branches ||--o{ documents : ""

    audit_logs { bigint id PK
        timestamp occurred_at
        string action
        string subject_type "morph alias"
        bigint subject_id
        bigint branch_id "no FK"
        char request_id
        json changes "redacted diff" }
    documents { bigint id PK
        uuid uuid UK
        string category
        char sha256
        string scan_status }
```
