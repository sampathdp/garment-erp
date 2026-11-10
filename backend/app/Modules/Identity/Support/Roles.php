<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Support\Permissions as P;

/**
 * Initial (system) roles. Administrators can edit their permissions (except super_admin)
 * and create additional roles. Code never checks these names; it checks permissions.
 *
 * Scope model:
 *  - Page-scoped roles (Page Owner, Second Admin) see every branch but only their assigned pages.
 *  - Task roles (designers, cutting staff) hold scope.* but only `*.view_assigned`, so they see
 *    only the records assigned to them.
 *  - Delivery staff are branch-scoped (assigned branches, all pages).
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const PAGE_OWNER = 'page_owner';

    public const SECOND_ADMIN = 'second_admin';

    public const DESIGN_DESIGNER = 'design_designer';

    public const BULK_DESIGNER = 'bulk_designer';

    public const OPERATION_ADMIN = 'operation_admin';

    public const EXTERNAL_BULK_DESIGNER = 'external_bulk_designer';

    public const CUTTING_STAFF = 'cutting_staff';

    public const DELIVERY_STAFF = 'delivery_staff';

    /** Roles whose permissions can never be changed from the UI. */
    public const LOCKED = [self::SUPER_ADMIN];

    /**
     * @return array<string, array{label: string, description: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        $assignedTaskScope = [P::SCOPE_ALL_BRANCHES, P::SCOPE_ALL_PAGES];

        return [
            self::SUPER_ADMIN => [
                'label' => 'Super Admin',
                'description' => 'System configuration, users and roles. Every permission.',
                'permissions' => P::all(),
            ],
            self::PAGE_OWNER => [
                'label' => 'Page Owner',
                'description' => 'Owns the orders of assigned pages: customers, design instructions, garment lists and approvals.',
                'permissions' => [
                    P::SCOPE_ALL_BRANCHES, P::BRANCHES_VIEW, P::PAGES_VIEW,
                    P::CUSTOMERS_VIEW, P::CUSTOMERS_MANAGE, P::PRICING_VIEW,
                    P::ORDERS_VIEW, P::ORDERS_CREATE, P::ORDERS_UPDATE, P::ORDERS_DISCOUNT, P::ORDERS_SUBMIT, P::ORDERS_HOLD, P::ORDERS_CANCEL,
                    P::PAYMENTS_VIEW, P::PAYMENTS_RECORD, P::INVOICES_VIEW,
                    P::DESIGNS_VIEW, P::DESIGNS_ASSIGN, P::DESIGNS_INSTRUCT, P::DESIGNS_RECORD_REVIEW, P::DESIGNS_APPROVE,
                    P::GARMENT_LISTS_VIEW, P::GARMENT_LISTS_MANAGE, P::GARMENT_LISTS_CONFIRM,
                    P::BULK_VIEW, P::BULK_APPROVE,
                    P::PRODUCTION_VIEW, P::ISSUES_REPORT,
                    P::PRINTING_VIEW, P::PRINTING_APPROVE_SAMPLE,
                    P::QC_VIEW, P::DELIVERY_VIEW, P::REPORTS_VIEW,
                ],
            ],
            self::SECOND_ADMIN => [
                'label' => 'Second Admin',
                'description' => 'Verifies payments and performs authorized financial actions for assigned pages.',
                'permissions' => [
                    P::SCOPE_ALL_BRANCHES, P::BRANCHES_VIEW, P::PAGES_VIEW,
                    P::CUSTOMERS_VIEW, P::ORDERS_VIEW,
                    P::ORDERS_URGENT_APPROVE_ADMIN,
                    P::PAYMENTS_VIEW, P::PAYMENTS_VERIFY, P::PAYMENTS_REFUND,
                    P::INVOICES_VIEW, P::INVOICES_ISSUE, P::INVOICES_VOID,
                    P::DELIVERY_VIEW, P::REPORTS_VIEW,
                ],
            ],
            self::DESIGN_DESIGNER => [
                'label' => 'Design Designer',
                'description' => 'Works on assigned design tasks: uploads versions and responds to corrections.',
                'permissions' => [...$assignedTaskScope, P::DESIGNS_VIEW_ASSIGNED, P::DESIGNS_UPLOAD_VERSION, P::DESIGNS_RESPOND_CORRECTION],
            ],
            self::BULK_DESIGNER => [
                'label' => 'Bulk Designer',
                'description' => 'Works on assigned bulk jobs: measurements, bulk files and checklists.',
                'permissions' => [...$assignedTaskScope, P::BULK_VIEW_ASSIGNED, P::BULK_MEASURE, P::BULK_UPLOAD_FILES, P::BULK_COMPLETE_CHECKLIST, P::ISSUES_REPORT],
            ],
            self::OPERATION_ADMIN => [
                'label' => 'Operation Admin',
                'description' => 'Runs operations: bulk assignment, printing, samples, cutting, sewing and production issues.',
                'permissions' => [
                    P::SCOPE_ALL_BRANCHES, P::SCOPE_ALL_PAGES, P::BRANCHES_VIEW, P::PAGES_VIEW,
                    P::CUSTOMERS_VIEW, P::ORDERS_VIEW, P::ORDERS_HOLD, P::ORDERS_URGENT_APPROVE_OPERATIONS, P::PAYMENTS_VIEW,
                    P::DESIGNS_VIEW, P::GARMENT_LISTS_VIEW,
                    P::PRODUCTION_VIEW, P::BULK_VIEW, P::BULK_ASSIGN, P::BULK_MANAGE_EXTERNAL, P::BULK_CONFIGURE,
                    P::PRINTING_VIEW, P::PRINTING_MANAGE, P::PRINTING_UPLOAD_SAMPLE,
                    P::ISSUES_REPORT, P::ISSUES_MANAGE,
                    P::CUTTING_VIEW, P::CUTTING_MANAGE, P::SEWING_MANAGE,
                    P::QC_VIEW, P::GATE_PASSES_VIEW, P::GATE_PASSES_REQUEST, P::DELIVERY_VIEW,
                ],
            ],
            self::EXTERNAL_BULK_DESIGNER => [
                'label' => 'External Bulk Designer',
                'description' => 'External (supplier-side) designer working only on bulk jobs assigned to them.',
                'permissions' => [...$assignedTaskScope, P::BULK_VIEW_ASSIGNED, P::BULK_MEASURE, P::BULK_UPLOAD_FILES, P::BULK_COMPLETE_CHECKLIST],
            ],
            self::CUTTING_STAFF => [
                'label' => 'Cutting Staff',
                'description' => 'Works on assigned cutting jobs: checklists and issue reports.',
                'permissions' => [...$assignedTaskScope, P::CUTTING_VIEW_ASSIGNED, P::CUTTING_UPDATE_CHECKLIST, P::ISSUES_REPORT],
            ],
            self::DELIVERY_STAFF => [
                'label' => 'Delivery Staff',
                'description' => 'Dispatches and confirms deliveries of assigned branches and records cash on delivery.',
                'permissions' => [
                    P::SCOPE_ALL_PAGES, P::BRANCHES_VIEW,
                    P::ORDERS_VIEW, P::DELIVERY_VIEW, P::DELIVERY_DISPATCH, P::DELIVERY_CONFIRM,
                    P::PAYMENTS_RECORD, P::GATE_PASSES_VIEW,
                ],
            ],
        ];
    }
}
