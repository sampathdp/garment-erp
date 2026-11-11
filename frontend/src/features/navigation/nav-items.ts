/**
 * Authorization-aware navigation. `permissions` hides items the user cannot use (UX only);
 * the API enforces every permission independently. Screens for later phases are added here
 * once they exist.
 */
export interface NavItem {
  label: string;
  href: string;
  /** Any one of these permissions shows the item. Omit for items every signed-in user sees. */
  permissions?: string[];
  section: "main" | "settings" | "account";
}

export const NAV_ITEMS: NavItem[] = [
  { label: "Dashboard", href: "/dashboard/", section: "main" },
  { label: "New order", href: "/orders/new/", permissions: ["orders.create"], section: "main" },
  { label: "Orders", href: "/orders/", permissions: ["orders.view"], section: "main" },
  { label: "Drafts & pending payment", href: "/orders/pending/", permissions: ["orders.view"], section: "main" },
  { label: "Urgent approvals", href: "/orders/urgent/", permissions: ["orders.urgent.approve_admin", "orders.urgent.approve_operations", "orders.create"], section: "main" },
  { label: "Approved orders", href: "/orders/approved/", permissions: ["orders.view"], section: "main" },
  { label: "Payment verification", href: "/payments/", permissions: ["payments.view"], section: "main" },
  { label: "Customers", href: "/customers/", permissions: ["customers.view"], section: "main" },
  { label: "Design plan", href: "/designs/", permissions: ["designs.view", "designs.view_assigned"], section: "main" },
  { label: "Bulk management", href: "/bulk-jobs/", permissions: ["bulk.view"], section: "main" },
  { label: "My bulk jobs", href: "/bulk-jobs/mine/", permissions: ["bulk.view_assigned"], section: "main" },
  { label: "Printing", href: "/printing/", permissions: ["printing.view"], section: "main" },
  { label: "Cutting", href: "/cutting/", permissions: ["cutting.view", "cutting.view_assigned"], section: "main" },
  { label: "Production issues", href: "/issues/", permissions: ["production.view", "production.issues.manage", "production.issues.report"], section: "main" },
  { label: "Sewing", href: "/sewing/", permissions: ["sewing.manage"], section: "main" },
  { label: "Deliveries", href: "/deliveries/", permissions: ["delivery.view"], section: "main" },
  { label: "Reports", href: "/reports/", permissions: ["reports.view"], section: "main" },
  { label: "Price lists", href: "/settings/pricing/", permissions: ["pricing.view"], section: "settings" },
  { label: "List fields & sizes", href: "/settings/garment-setup/", permissions: ["pricing.view", "pricing.manage"], section: "settings" },
  { label: "Workflow settings", href: "/settings/workflow/", permissions: ["settings.manage"], section: "settings" },
  { label: "Bulk setup", href: "/settings/bulk-setup/", permissions: ["bulk.configure"], section: "settings" },
  { label: "Cutting setup", href: "/settings/cutting-setup/", permissions: ["cutting.configure"], section: "settings" },
  { label: "Order types & categories", href: "/settings/catalog/", permissions: ["pricing.view", "pricing.manage"], section: "settings" },
  { label: "Users", href: "/settings/users/", permissions: ["users.view"], section: "settings" },
  { label: "Roles", href: "/settings/roles/", permissions: ["roles.view", "roles.manage"], section: "settings" },
  { label: "Companies & shops", href: "/settings/pages/", permissions: ["pages.view"], section: "settings" },
  { label: "Notifications", href: "/notifications/", section: "account" },
  { label: "My account", href: "/account/", section: "account" },
];

export const NAV_SECTIONS: { key: NavItem["section"]; label: string | null }[] = [
  { key: "main", label: null },
  { key: "settings", label: "Administration" },
  { key: "account", label: null },
];

export function visibleNavItems(items: NavItem[], permissions: ReadonlySet<string> | string[]): NavItem[] {
  const granted = permissions instanceof Set ? permissions : new Set(permissions);
  return items.filter((item) => !item.permissions || item.permissions.some((p) => granted.has(p)));
}

/**
 * True when `pathname` is the item's page or one of its sub-pages (e.g. /settings/users/view/).
 * When `allHrefs` is provided, ensures only the most specific matching nav item is active.
 */
export function isActivePath(pathname: string, href: string, allHrefs?: string[]): boolean {
  const base = href.replace(/\/$/, "");
  const cleanPath = pathname.replace(/\/$/, "");
  const isMatch = cleanPath === base || cleanPath.startsWith(`${base}/`);
  if (!isMatch) return false;

  if (allHrefs && allHrefs.length > 0) {
    const hasMoreSpecific = allHrefs.some((other) => {
      if (other === href) return false;
      const otherBase = other.replace(/\/$/, "");
      return (
        (cleanPath === otherBase || cleanPath.startsWith(`${otherBase}/`)) &&
        otherBase.length > base.length
      );
    });
    if (hasMoreSpecific) return false;
  }

  return true;
}
