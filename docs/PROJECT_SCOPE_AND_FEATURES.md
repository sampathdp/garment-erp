# Garment ERP — Project Scope and Functional Features

---

## 1. Executive Summary

The **Garment Manufacturing & Production Management ERP** is a centralized, end-to-end operational platform designed specifically for custom apparel and sportswear manufacturing businesses. 

The system bridges the gap between front-end customer sales (primarily social media and branch sales channels) and the factory floor. It enforces strict business rules, financial controls, multi-tier approvals, quality checkpoints, and stage-by-stage accountability—from initial customer contact to doorstep delivery and final financial settlement.

---

## 2. Business Scope & Organizational Model

### 2.1 Single Enterprise with Multiple Branches
The system supports a single manufacturing company operating across multiple physical branches and production facilities. Data and operations are organized around physical factory locations while maintaining consolidated central oversight.

### 2.2 Sales Channels ("Pages")
Orders originate through specialized brand channels, primarily **Facebook/Social Media Pages**. Each page represents a distinct commercial brand or sales channel with its own:
- Dedicated sales and page management team (**Page Owners**)
- Brand-specific customer base
- Customized product pricing structures and quantity tiers
- Distinct invoice numbering sequences and branding headers

### 2.3 Hybrid Manufacturing Capability
The platform orchestrates both:
- **Internal Production**: In-house design studios, bulk preparation, printing, cutting, and tailoring facilities.
- **External Subcontracting**: Outsourced printing facilities and external garment manufacturing suppliers, fully tracked using formal gate passes and assignment histories.

---

## 3. User Roles & Operational Responsibilities

The system structures user access around specialized job responsibilities, ensuring strict separation of duties and preventing unauthorized operational actions:

| Role | Operational Scope | Key Responsibilities |
| :--- | :--- | :--- |
| **Super Admin** | Company-wide | Complete system configuration, staff accounts, role permissions, branch and page setup, master catalogs, and override controls. |
| **Page Owner (Sales Lead)** | Assigned Sales Pages | Managing incoming customer inquiries, creating orders, recording advance payment slips, submitting design instructions, customer review communication, and approving final designs, bulk specifications, and print samples. |
| **Second Admin (Accounts / Finance)** | Assigned Pages / Company-wide | Independent verification of advance payments, review of cash-on-delivery collections, issuing official invoices, handling credit notes, and evaluating urgent order requests. |
| **Operation Admin (Factory Manager)** | Factory / Production-wide | Central production scheduling, urgent order manufacturing approval, assigning internal vs. external bulk jobs, managing printing queues, supervising cutting and sewing, and overseeing quality control and production issues. |
| **Design Designer** | Assigned Design Tasks | Creating artwork, preparing sportswear mockups, uploading design revisions in response to customer feedback, and providing production-ready vector/print files. |
| **Bulk Production Designer** | Assigned Production Jobs | Setting up bulk measurements (fabric meterage, patch meters, collar specs), completing production readiness checklists, and packaging digital assets for the print floor. |
| **External Bulk Designer** | Assigned Outsourced Jobs | External supplier personnel recording measurements and checklist progress for outsourced production runs. |
| **Cutting Staff / Cutter** | Assigned Cutting Jobs | Executing fabric cutting, logging physical cut quantities by size, inspecting cut bundles against mandatory checklists, and flagging fabric defects. |
| **Delivery Staff** | Assigned Branches | Packaging finished orders, assigning couriers or internal drivers, uploading delivery waybills and proof of delivery, and collecting Cash on Delivery (COD). |

---

## 4. End-to-End Order Lifecycle & Workflow

The platform guides every garment order through a sequential, state-controlled pipeline where each stage must satisfy predefined criteria before moving forward:

```
[ Customer Inquiry & Order Intake ]
               │
               ▼
   [ Payment Verification ] ──(Must achieve minimum 30% verified advance)
               │
               ▼
[ Urgency Assessment ] ───────(If < 7 working days, requires Dual Approval)
               │
               ▼
      [ Approved Order ]
               │
               ▼
    [ Design & Proofing ] ────(Customer reviews, revisions & final sign-off)
               │
               ▼
[ Name & Number List Lock ] ──(Sizing, roster data locked & invoice generated)
               │
               ▼
    [ Bulk Preparation ] ─────(Measurements, fabric checks & job card generation)
               │
               ▼
  [ Printing & Sublimation ] ─(Physical sample approval required before run)
               │
               ▼
  [ Cutting & Inspection ] ───(Piece count verification & quality criteria)
               │
               ▼
      [ Tailoring / Sewing ] ──(Assembly of cut garments into finished pieces)
               │
               ▼
  [ Dispatch & Delivery ] ────(Courier waybill, proof of delivery & COD tracking)
               │
               ▼
   [ Final Settlement ] ──────(Zero outstanding balance & complete audit closure)
```

---

## 5. Detailed Functional Features by Module

### 5.1 Customer Relationship & Order Intake
- **Customer Profiles**: Centralized customer records linked to sales pages, capturing names, verified contact numbers (E.164 standard), WhatsApp numbers, and delivery addresses.
- **Duplicate Prevention**: Automatic duplicate detection on phone numbers prevents duplicate customer profiles while allowing legitimate repeat business.
- **Structured Order Intake**: Fast, guided order entry capturing:
  - Associated Sales Page and Manufacturing Branch
  - Order classification (Standard Single Order or Bulk Order)
  - Target product category (Jerseys, Arm Cuts, Shorts, Bottoms, T-Shirts)
  - Expected customer delivery due date
  - Estimated gross quantity and agreed base pricing
- **Strict Job Identification**: Upon submission, each order receives a permanent, gap-free, non-reusable Job Number (e.g., `COL-FB01-26-00124`) reflecting branch, page, year, and sequential order.

### 5.2 Financial Control & Advance Payment Management
- **30% Advance Requirement**: Production cannot begin until at least 30% of the gross order total (including delivery fees and taxes) has been received and verified.
- **Separation of Duties (Anti-Fraud)**: The staff member who records a payment (Sales/Page Owner) **cannot** verify it. An independent Second Admin (Accounts) must inspect and approve the payment evidence.
- **Payment Evidence Vault**: Secure attachment of bank deposit slips, online transfer receipts, or cheque scans.
- **Payment Verification History**: Permanent audit trail of submitted, approved, or rejected payments with timestamps and reviewer remarks.
- **Idempotency Protection**: Safeguards preventing duplicate payment entries during slow connections or multiple clicks.

### 5.3 Seven-Day Urgent Order Management & Dual-Approval System
- **Automated Urgency Detection**: The system calculates the exact number of working days between order submission and the requested delivery date, accounting for business working calendars and official Sri Lankan holidays.
- **The Urgent Order Boundary**: Orders required in fewer than 7 working days are automatically flagged as **Urgent**.
- **Dual Mandatory Approvals**: To protect factory operations, urgent orders cannot be released until approved by **both**:
  1. **Second Admin** (Commercial / Financial viability)
  2. **Operation Admin** (Factory floor capacity & production feasibility)
  Both approvals must come from two distinct individuals. If either manager rejects the request, the order remains blocked until the customer agrees to a realistic delivery date.

### 5.4 Custom Design & Artwork Approval Workflow
- **Post-Payment Job Specification**: Once the advance payment is verified, Page Owners enter detailed fabric choices, styling notes, and design guidelines.
- **Automated Task Assignment**: Orders automatically route to the assigned Design Designer's queue, sorted by urgency and due date.
- **Immutable Version History**: Every uploaded artwork revision is saved as a permanent, immutable version. Earlier design iterations are never overwritten.
- **Customer Proofing & Evidence**: Customers do not need to log into the software. Staff communicate with customers (via WhatsApp, Facebook Messenger, phone, or in person) and record customer approvals directly in the system, accompanied by proof screenshots.
- **Correction Limits**: Configurable round limits for design modifications (e.g., standard 3 free correction rounds) prevent infinite revisions, requiring administrative override for excess changes.
- **Page Owner Sign-Off**: Only the designated Page Owner can give the final commercial approval on behalf of the customer.

### 5.5 Player Name & Number List Management
- **Category-Specific Customization Fields**: Dynamic roster forms tailored to each garment type:
  - *Jerseys / T-Shirts*: Player Name, Player Number, Sleeve Type (Short/Long/Cut), Size, Adult/Kids fit.
  - *Shorts / Bottoms*: Player Number, Logo Requirements, Pocket options.
- **Flexible Data Input**: Direct manual entry, bulk multi-row paste, and complete CSV import/export.
- **Data Validation & Duplicate Warnings**: Immediate detection of missing required sizes, mismatched totals against the order quantity, and automatic alerts for duplicate player jersey numbers.
- **List Locking**: When finalized, the garment list is locked against further modification to ensure printing and cutting match the exact customer roster.
- **Audited Administrative Correction**: If a customer requests a late roster change, unlocking the list requires a mandatory reason, logs an audit entry, alerts management, and invalidates any subsequent production steps that relied on the old version.

### 5.6 Automated Page Pricing & Invoicing
- **Rule-Based Pricing Matrix**: Automated calculation based on product category, fabric type, quantity tiers (volume discounts), and optional add-ons (sleeves, collar types, special sizes).
- **Price Freeze (Snapshotting)**: Agreed unit prices are locked into the order at confirmation time; future price catalog changes never alter active orders.
- **Automated Invoice Generation**: When the garment list is locked, the system automatically creates an official invoice with a sequential, page-specific invoice number.
- **Automatic Advance Recalculation**: If late quantity changes increase the order total, the system recalculates the required 30% advance. If the paid amount falls below 30%, production is automatically held until the balance is topped up.

### 5.7 Bulk Production & Job Card Generation
- **Automated Bulk Job Creation**: Created automatically once the design is approved, the garment list is locked, the invoice is issued, and the advance payment is verified.
- **Work Assignment**: Flexible dispatch to internal factory teams or external manufacturing contractors.
- **Fabric Meterage & Measurement Tracking**: Precise recording of fabric requirements (Bulk Meter, Patch Meter, Collar Meter) with complete change history.
- **Stage Checklists**: Standard operating procedure checklists that floor supervisors must complete prior to releasing the job to printing.
- **Live Digital Job Card**: A consolidated, single-screen production blueprint pulling together the approved artwork preview, fabric details, meterage, and full size-by-size breakdown.

### 5.8 Fabric Printing & Sublimation Control
- **Controlled Printing Pipeline**: Tracked transition through *Ready for Printing*, *Sent to Printer*, *Sample Review*, and *Physical Printing In Progress*.
- **Mandatory Physical Sample Sign-Off**: The printer uploads high-resolution photos of a physical printed fabric sample. Production cannot proceed until the Page Owner reviews and approves this physical sample.
- **Document Printing Lock (Zero-Waste Safeguard)**: To prevent printing garments using unapproved colors or flawed designs, the system **physically locks** the printing of Job Cards and production lists until the physical sample is formally approved.
- **Gate Pass Recording**: Movement of fabric to and from external printing facilities is logged with gate pass identifiers, dates, and handling notes.

### 5.9 Cutting, Tailoring & Production Quality Control
- **Cutter Assignment**: Allocation of jobs to individual cutters with targeted completion deadlines.
- **Cut Verification**: Recording actual cut quantities size-by-size against ordered targets, automatically highlighting shortages or missing items.
- **Mandatory Cutting Quality Checks**: Inspection checklists covering fabric flaws, alignment, cutting accuracy, and bundle tagging.
- **Sewing & Garment Assembly**: Tracking transfer from the cutting tables into the sewing lines, verifying that received pieces match finished sewn garments.

### 5.10 Centralized Production Issue & Defect Tracking
- **Factory Floor Issue Register**: Centralized logging for any anomaly occurring on the production floor:
  - Fabric defects or damages
  - Printing or color sublimation errors
  - Cutting inaccuracies or missing sleeves/panels
  - Missing roster names or numbers
  - Machine breakdowns or material delays
- **Photographic Evidence & Severity**: Floor staff record affected quantities, defective sizes, descriptive notes, and defect photos.
- **Production Blockers**: Critical issues automatically halt subsequent production stages, notifying operation managers and assigned designers immediately. The job cannot be closed until the issue is investigated, resolved, and signed off.

### 5.11 Dispatch, Courier Management & Cash on Delivery (COD)
- **Flexible Delivery Methods**:
  - Courier Service (tracking number, courier partner name)
  - Company Delivery Vehicle (driver name, vehicle route)
  - Customer Factory Pickup
- **Mandatory Delivery Documentation**: Requirement of proof documents based on method:
  - *Courier*: Courier waybill receipt
  - *Company Vehicle*: Signed delivery sheet
  - *Customer Pickup*: Photo of customer collection or signed receipt
- **Cash on Delivery (COD) Automation**:
  - Outstanding balance is automatically calculated upon dispatch.
  - Delivery staff log collected cash directly into the system upon delivery.
  - Collected COD payments remain pending until Accounts independently verifies the deposit into company bank accounts.
- **Final Job Completion Criteria**: An order is only permitted to reach "Completed" status when:
  1. All production stages (Printing, Cutting, Sewing) are finished
  2. All mandatory quality checklists have passed
  3. No open, blocking production issues remain
  4. Delivery proof documents are uploaded
  5. The **verified financial balance is exactly zero**

### 5.12 Role-Based Dashboards & Business Analytics
- **Personalized Operational Views**: Every user lands on a dashboard customized to their specific daily duties:
  - *Page Owners*: Orders awaiting customer feedback, pending advance payments, sample reviews.
  - *Designers*: Active design queue ordered by urgency and delivery due dates.
  - *Factory Managers*: Real-time status of bulk jobs, printing queues, cutting progress, and open factory issues.
  - *Delivery Team*: Orders ready for packing, out for delivery, and pending COD collections.
- **Executive Management Reports**:
  - Orders by Stage and Status
  - Branch and Sales Page Performance Comparison
  - Overdue Orders & Impending Deadline Risk Analysis
  - Financial Health: Total Sales, Advance Payments Collected, and Outstanding Balances
  - Production Turnaround Times (Design Lead Time, Cutting Duration, Printing Speed)
  - Factory Defect & Quality Issue Summaries

### 5.13 In-App Notification & Alert Engine
- Immediate in-system notification alerts keep teams synchronized across locations:
  - New orders submitted for payment verification
  - Urgent orders requiring executive review
  - Customer design corrections requested
  - Production samples awaiting sign-off
  - Production defects logged on the factory floor
  - Orders packaged and ready for dispatch

---

## 6. Core Business Guarantees & Safeguards

The platform is built around strict operational rules that protect the business from revenue loss, wasted materials, and customer disputes:

1. **No Unpaid Production**: Factory work cannot begin without a verified 30% advance payment.
2. **No Single-Person Urgent Bypass**: Urgent deadlines require agreement from both Finance and Factory Operations.
3. **No Unapproved Printing**: Fabric printing cannot begin until the customer-approved physical sample is verified.
4. **No Unverified Financial Closures**: Delivery staff cannot self-clear collected cash; Accounts must verify all collections.
5. **Immutable Versioning**: Design files and invoices cannot be secretly edited or overwritten; all changes create versioned historical records.
6. **Strict Separation of Duties**: Staff cannot approve their own financial entries or override critical production gates without authorized permissions.
7. **Complete Audit Trail**: Every status change, approval, price adjustment, and document upload is permanently recorded with the staff member's name and exact timestamp.

---

## 7. Out of Scope / Deliberate Non-Inclusions

To maintain focus on core internal manufacturing efficiency, the following areas are intentionally outside the current platform scope:
- **Customer Self-Service Portal**: Customers do not log into the application; internal sales staff manage all customer communication and approvals.
- **Supplier Portal**: External contractors do not have independent portal logins; external jobs are managed by internal coordinators.
- **Automated Bank Feeds**: Direct real-time bank account API integration is excluded; payments are verified through uploaded deposit slips and payment proofs.
- **Raw Material Stock / Yarn Inventory**: Raw fabric warehousing, thread inventory, and ink chemical stock control are excluded.
- **General Ledger & Corporate Accounting**: Full balance sheet, double-entry general ledger, and payroll are excluded (the system manages order-level billing and payments, not company payroll or tax filing).
- **Direct Courier System API Sync**: Courier waybills and tracking numbers are recorded manually by staff rather than through automated carrier API handshakes.
