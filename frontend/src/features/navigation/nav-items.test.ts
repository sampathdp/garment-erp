import { describe, expect, it } from "vitest";
import { isActivePath, NAV_ITEMS, visibleNavItems, type NavItem } from "./nav-items";

const items: NavItem[] = [
  { label: "Dashboard", href: "/dashboard/", section: "main" },
  { label: "Branches", href: "/settings/branches/", permissions: ["branches.view"], section: "settings" },
  { label: "Payments", href: "/payments/", permissions: ["payments.view", "payments.verify"], section: "main" },
];

describe("visibleNavItems", () => {
  it("shows unrestricted items to everyone", () => {
    expect(visibleNavItems(items, []).map((i) => i.label)).toEqual(["Dashboard"]);
  });

  it("shows items when any listed permission is granted", () => {
    expect(visibleNavItems(items, ["payments.verify"]).map((i) => i.label)).toEqual(["Dashboard", "Payments"]);
    expect(visibleNavItems(items, new Set(["branches.view"])).map((i) => i.label)).toEqual(["Dashboard", "Branches"]);
  });

  it("hides administration from staff without administration permissions", () => {
    const cuttingStaff = ["cutting.view_assigned", "cutting.update_checklist", "production.issues.report"];
    expect(visibleNavItems(NAV_ITEMS, cuttingStaff).map((i) => i.label)).toEqual(["Dashboard", "Cutting", "Production issues", "Notifications", "My account"]);

    const admin = ["users.view", "roles.view", "branches.view", "pages.view"];
    expect(visibleNavItems(NAV_ITEMS, admin).map((i) => i.label)).toEqual(["Dashboard", "Users", "Roles", "Companies & shops", "Notifications", "My account"]);
  });
});

describe("isActivePath", () => {
  it("matches the page and its record sub-pages", () => {
    expect(isActivePath("/settings/users/", "/settings/users/")).toBe(true);
    expect(isActivePath("/settings/users/view/", "/settings/users/")).toBe(true);
    expect(isActivePath("/settings/users-archive/", "/settings/users/")).toBe(false);
  });

  it("disambiguates nested nav items using allHrefs", () => {
    const all = ["/orders/", "/orders/pending/", "/orders/urgent/", "/orders/approved/"];
    // When on /orders/pending/, only /orders/pending/ should be active, not /orders/
    expect(isActivePath("/orders/pending/", "/orders/pending/", all)).toBe(true);
    expect(isActivePath("/orders/pending/", "/orders/", all)).toBe(false);

    // When on /orders/, /orders/ is active
    expect(isActivePath("/orders/", "/orders/", all)).toBe(true);
    expect(isActivePath("/orders/", "/orders/pending/", all)).toBe(false);

    // When on an order detail view (/orders/view/?id=1), /orders/ is active
    expect(isActivePath("/orders/view/", "/orders/", all)).toBe(true);
    expect(isActivePath("/orders/view/", "/orders/pending/", all)).toBe(false);
  });
});
