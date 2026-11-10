<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Permission catalogue.
 *
 * Code checks PERMISSIONS, never role names. Roles (see Roles) are bundles that administrators
 * can edit in the UI. A new permission needs code that checks it, so permissions are added here
 * (not in the UI) and the PermissionSeeder is re-run.
 *
 * Naming: `<area>.view` sees every record inside the user's branch/page scope;
 * `<area>.view_assigned` sees only records assigned to the user (designer, cutting staff, ...).
 */
final class Permissions
{
    // Data scope (filters data, never authorizes an action on its own)
    public const SCOPE_ALL_BRANCHES = 'scope.all_branches';

    public const SCOPE_ALL_PAGES = 'scope.all_pages';

    // Administration
    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const BRANCHES_VIEW = 'branches.view';

    public const BRANCHES_MANAGE = 'branches.manage';

    public const PAGES_VIEW = 'pages.view';

    public const PAGES_MANAGE = 'pages.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const HOLIDAYS_MANAGE = 'holidays.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    // Customers and pricing
    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const PRICING_VIEW = 'pricing.view';

    public const PRICING_MANAGE = 'pricing.manage';

    // Orders ("jobs")
    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_CREATE = 'orders.create';

    public const ORDERS_SUBMIT = 'orders.submit';

    public const ORDERS_HOLD = 'orders.hold';

    public const ORDERS_CANCEL = 'orders.cancel';

    public const ORDERS_CANCEL_LATE = 'orders.cancel_late';

    public const ORDERS_UPDATE = 'orders.update';

    public const ORDERS_DISCOUNT = 'orders.discount';

    // Urgent orders need BOTH approvals, from two different people (WORKFLOW_REQUIREMENTS.md §3.3).
    public const ORDERS_URGENT_APPROVE_ADMIN = 'orders.urgent.approve_admin';

    public const ORDERS_URGENT_APPROVE_OPERATIONS = 'orders.urgent.approve_operations';

    public const ORDERS_COMPLETE = 'orders.complete';

    // Payments and invoices
    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_RECORD = 'payments.record';

    public const PAYMENTS_VERIFY = 'payments.verify';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const INVOICES_VIEW = 'invoices.view';

    public const INVOICES_ISSUE = 'invoices.issue';

    public const INVOICES_VOID = 'invoices.void';

    // Design
    public const DESIGNS_VIEW = 'designs.view';

    public const DESIGNS_VIEW_ASSIGNED = 'designs.view_assigned';

    public const DESIGNS_ASSIGN = 'designs.assign';

    public const DESIGNS_INSTRUCT = 'designs.instruct';

    public const DESIGNS_UPLOAD_VERSION = 'designs.upload_version';

    public const DESIGNS_RESPOND_CORRECTION = 'designs.respond_correction';

    public const DESIGNS_RECORD_REVIEW = 'designs.record_review';

    public const DESIGNS_APPROVE = 'designs.approve';

    public const DESIGNS_CORRECTION_OVERRIDE = 'designs.correction_override';

    // Garment (name/number) lists
    public const GARMENT_LISTS_VIEW = 'garment_lists.view';

    public const GARMENT_LISTS_MANAGE = 'garment_lists.manage';

    public const GARMENT_LISTS_CONFIRM = 'garment_lists.confirm';

    public const GARMENT_LISTS_UNLOCK = 'garment_lists.unlock';

    // Bulk production
    public const BULK_VIEW = 'bulk.view';

    public const BULK_VIEW_ASSIGNED = 'bulk.view_assigned';

    public const BULK_ASSIGN = 'bulk.assign';

    public const BULK_MEASURE = 'bulk.measure';

    public const BULK_UPLOAD_FILES = 'bulk.upload_files';

    public const BULK_COMPLETE_CHECKLIST = 'bulk.complete_checklist';

    public const BULK_APPROVE = 'bulk.approve';

    public const BULK_MANAGE_EXTERNAL = 'bulk.manage_external';

    public const BULK_ASSIGNMENT_OVERRIDE = 'bulk.assignment_override';

    public const BULK_CORRECT_MEASUREMENTS = 'bulk.correct_measurements';

    public const BULK_CONFIGURE = 'bulk.configure';

    // Operations
    public const PRODUCTION_VIEW = 'production.view';

    public const ISSUES_REPORT = 'production.issues.report';

    public const ISSUES_MANAGE = 'production.issues.manage';

    public const PRINTING_VIEW = 'printing.view';

    public const PRINTING_MANAGE = 'printing.manage';

    public const PRINTING_UPLOAD_SAMPLE = 'printing.upload_sample';

    public const PRINTING_APPROVE_SAMPLE = 'printing.approve_sample';

    public const CUTTING_VIEW = 'cutting.view';

    public const CUTTING_VIEW_ASSIGNED = 'cutting.view_assigned';

    public const CUTTING_MANAGE = 'cutting.manage';

    public const CUTTING_UPDATE_CHECKLIST = 'cutting.update_checklist';

    public const SEWING_MANAGE = 'sewing.manage';

    public const QC_VIEW = 'qc.view';

    public const QC_INSPECT = 'qc.inspect';

    public const GATE_PASSES_VIEW = 'gate_passes.view';

    public const GATE_PASSES_REQUEST = 'gate_passes.request';

    public const GATE_PASSES_APPROVE = 'gate_passes.approve';

    public const GATE_PASSES_CHECK = 'gate_passes.check';

    public const DELIVERY_VIEW = 'delivery.view';

    public const DELIVERY_DISPATCH = 'delivery.dispatch';

    public const DELIVERY_CONFIRM = 'delivery.confirm';

    public const REPORTS_VIEW = 'reports.view';

    /**
     * Grouped catalogue with human labels (used by the role editor).
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'scope' => ['label' => 'Data scope', 'permissions' => [
                self::SCOPE_ALL_BRANCHES => 'See data of all branches (not only assigned ones)',
                self::SCOPE_ALL_PAGES => 'See data of all pages (not only assigned ones)',
            ]],
            'administration' => ['label' => 'Administration', 'permissions' => [
                self::USERS_VIEW => 'View users',
                self::USERS_MANAGE => 'Create, edit, activate and deactivate users',
                self::ROLES_VIEW => 'View roles',
                self::ROLES_MANAGE => 'Create and edit roles',
                self::BRANCHES_VIEW => 'View branches',
                self::BRANCHES_MANAGE => 'Create and edit branches',
                self::PAGES_VIEW => 'View pages',
                self::PAGES_MANAGE => 'Create and edit pages and page members',
                self::AUDIT_VIEW => 'View audit trail and user activity',
                self::HOLIDAYS_MANAGE => 'Manage holiday calendar',
                self::SETTINGS_MANAGE => 'Configure workflow settings (responsible designers, correction limit)',
            ]],
            'customers' => ['label' => 'Customers and pricing', 'permissions' => [
                self::CUSTOMERS_VIEW => 'View customers',
                self::CUSTOMERS_MANAGE => 'Manage customer information',
                self::PRICING_VIEW => 'View pricing',
                self::PRICING_MANAGE => 'Manage pricing, product categories and order types',
            ]],
            'orders' => ['label' => 'Orders (jobs)', 'permissions' => [
                self::ORDERS_VIEW => 'View orders in scope',
                self::ORDERS_CREATE => 'Create orders',
                self::ORDERS_SUBMIT => 'Submit orders',
                self::ORDERS_HOLD => 'Put orders on hold',
                self::ORDERS_CANCEL => 'Cancel orders',
                self::ORDERS_CANCEL_LATE => 'Cancel orders after production started',
                self::ORDERS_UPDATE => 'Edit job specifications before approval',
                self::ORDERS_DISCOUNT => 'Give discounts on orders and invoices',
                self::ORDERS_URGENT_APPROVE_ADMIN => 'Urgent orders: give the Second Admin approval',
                self::ORDERS_URGENT_APPROVE_OPERATIONS => 'Urgent orders: give the Operation Admin approval',
                self::ORDERS_COMPLETE => 'Complete orders',
            ]],
            'finance' => ['label' => 'Payments and invoices', 'permissions' => [
                self::PAYMENTS_VIEW => 'View payments',
                self::PAYMENTS_RECORD => 'Record payments',
                self::PAYMENTS_VERIFY => 'Verify or reject payments',
                self::PAYMENTS_REFUND => 'Record refunds',
                self::INVOICES_VIEW => 'View invoices',
                self::INVOICES_ISSUE => 'Issue invoices',
                self::INVOICES_VOID => 'Void invoices',
            ]],
            'design' => ['label' => 'Design', 'permissions' => [
                self::DESIGNS_VIEW => 'View all design tasks in scope',
                self::DESIGNS_VIEW_ASSIGNED => 'View design tasks assigned to me',
                self::DESIGNS_ASSIGN => 'Assign designers',
                self::DESIGNS_INSTRUCT => 'Manage design instructions',
                self::DESIGNS_UPLOAD_VERSION => 'Upload design versions (assigned tasks)',
                self::DESIGNS_RESPOND_CORRECTION => 'Respond to correction requests (assigned tasks)',
                self::DESIGNS_RECORD_REVIEW => 'Record customer design reviews',
                self::DESIGNS_APPROVE => 'Approve final designs (page owners of the page)',
                self::DESIGNS_CORRECTION_OVERRIDE => 'Request corrections beyond the correction limit',
            ]],
            'garment_lists' => ['label' => 'Garment lists', 'permissions' => [
                self::GARMENT_LISTS_VIEW => 'View garment lists',
                self::GARMENT_LISTS_MANAGE => 'Edit unlocked garment lists',
                self::GARMENT_LISTS_CONFIRM => 'Confirm and lock garment lists',
                self::GARMENT_LISTS_UNLOCK => 'Correct confirmed garment lists (administrative correction)',
            ]],
            'bulk' => ['label' => 'Bulk production', 'permissions' => [
                self::BULK_VIEW => 'View all bulk jobs in scope',
                self::BULK_VIEW_ASSIGNED => 'View bulk jobs assigned to me',
                self::BULK_ASSIGN => 'Assign internal/external bulk production',
                self::BULK_MEASURE => 'Enter measurements (assigned jobs)',
                self::BULK_UPLOAD_FILES => 'Upload bulk files (assigned jobs)',
                self::BULK_COMPLETE_CHECKLIST => 'Complete bulk checklists (assigned jobs)',
                self::BULK_APPROVE => 'Review and approve bulk, record the client checklist confirmation (page owners of the page)',
                self::BULK_MANAGE_EXTERNAL => 'Record external bulk production (measurements, checklist, evidence, completion)',
                self::BULK_ASSIGNMENT_OVERRIDE => 'Change external bulk assignments or switch between internal and external',
                self::BULK_CORRECT_MEASUREMENTS => 'Correct bulk measurements after completion (reason required)',
                self::BULK_CONFIGURE => 'Configure bulk measurement fields, checklist templates and suppliers',
            ]],
            'operations' => ['label' => 'Operations', 'permissions' => [
                self::PRODUCTION_VIEW => 'View operational jobs',
                self::ISSUES_REPORT => 'Report production issues',
                self::ISSUES_MANAGE => 'Manage production issues',
                self::PRINTING_VIEW => 'View printing',
                self::PRINTING_MANAGE => 'Manage printing',
                self::PRINTING_UPLOAD_SAMPLE => 'Upload printing samples',
                self::PRINTING_APPROVE_SAMPLE => 'Approve printing samples',
                self::CUTTING_VIEW => 'View all cutting jobs in scope',
                self::CUTTING_VIEW_ASSIGNED => 'View cutting jobs assigned to me',
                self::CUTTING_MANAGE => 'Manage cutting jobs',
                self::CUTTING_UPDATE_CHECKLIST => 'Update cutting checklists (assigned jobs)',
                self::SEWING_MANAGE => 'Manage sewing',
                self::QC_VIEW => 'View quality control',
                self::QC_INSPECT => 'Record inspections',
                self::GATE_PASSES_VIEW => 'View gate passes',
                self::GATE_PASSES_REQUEST => 'Request gate passes',
                self::GATE_PASSES_APPROVE => 'Approve gate passes',
                self::GATE_PASSES_CHECK => 'Check gate passes out and in',
                self::DELIVERY_VIEW => 'View deliveries',
                self::DELIVERY_DISPATCH => 'Dispatch deliveries',
                self::DELIVERY_CONFIRM => 'Confirm deliveries',
                self::REPORTS_VIEW => 'View reports',
            ]],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        $all = [];
        foreach (self::groups() as $group) {
            array_push($all, ...array_keys($group['permissions']));
        }

        return $all;
    }

    /** @return list<string> */
    public static function scopePermissions(): array
    {
        return [self::SCOPE_ALL_BRANCHES, self::SCOPE_ALL_PAGES];
    }
}
