"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  AlertTriangleIcon,
  BellIcon,
  BoxesIcon,
  BuildingIcon,
  CheckCircleIcon,
  ClockIcon,
  CreditCardIcon,
  GlobeIcon,
  LayersIcon,
  LayoutDashboardIcon,
  LogOutIcon,
  MenuIcon,
  PaletteIcon,
  PlusIcon,
  ScissorsIcon,
  ShieldIcon,
  ShirtIcon,
  ShoppingBagIcon,
  SlidersIcon,
  TagIcon,
  UserIcon,
  UsersIcon,
  XIcon,
} from "@/components/ui/icons";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { useAuth } from "@/features/auth/auth-provider";
import { NotificationBell } from "@/features/notifications/notification-bell";
import { cn } from "@/lib/cn";
import { isActivePath, NAV_ITEMS, NAV_SECTIONS, visibleNavItems, type NavItem } from "./nav-items";

function navIcon(href: string) {
  switch (href) {
    case "/dashboard/":
      return <LayoutDashboardIcon size={17} />;
    case "/orders/new/":
      return <PlusIcon size={17} />;
    case "/orders/":
      return <ShoppingBagIcon size={17} />;
    case "/orders/pending/":
      return <ClockIcon size={17} />;
    case "/orders/urgent/":
      return <AlertTriangleIcon size={17} className="text-danger" />;
    case "/orders/approved/":
      return <CheckCircleIcon size={17} className="text-success" />;
    case "/payments/":
      return <CreditCardIcon size={17} />;
    case "/customers/":
      return <UsersIcon size={17} />;
    case "/designs/":
      return <PaletteIcon size={17} />;
    case "/bulk-jobs/":
    case "/bulk-jobs/mine/":
      return <BoxesIcon size={17} />;
    case "/printing/":
      return <ShirtIcon size={17} />;
    case "/cutting/":
      return <ScissorsIcon size={17} />;
    case "/issues/":
      return <AlertTriangleIcon size={17} />;
    case "/sewing/":
      return <LayersIcon size={17} />;
    case "/deliveries/":
      return <BoxesIcon size={17} />;
    case "/reports/":
      return <LayoutDashboardIcon size={17} />;
    case "/settings/bulk-setup/":
    case "/settings/cutting-setup/":
      return <SlidersIcon size={17} />;
    case "/settings/pricing/":
      return <TagIcon size={17} />;
    case "/settings/garment-setup/":
      return <ScissorsIcon size={17} />;
    case "/settings/workflow/":
      return <SlidersIcon size={17} />;
    case "/settings/catalog/":
      return <LayersIcon size={17} />;
    case "/settings/users/":
      return <UsersIcon size={17} />;
    case "/settings/roles/":
      return <ShieldIcon size={17} />;
    case "/settings/branches/":
      return <BuildingIcon size={17} />;
    case "/settings/pages/":
      return <GlobeIcon size={17} />;
    case "/notifications/":
      return <BellIcon size={17} />;
    case "/account/":
      return <UserIcon size={17} />;
    default:
      return <ShirtIcon size={17} />;
  }
}

/**
 * Authenticated shell. Redirects to /login when there is no session (UX only: the
 * static files contain no data; every API call is authorized by Laravel).
 */
export function AppShell({ children }: { children: ReactNode }) {
  const { status, user, logout } = useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const [confirmLogout, setConfirmLogout] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  useEffect(() => {
    if (status === "unauthenticated") {
      router.replace(`/login/?next=${encodeURIComponent(pathname)}`);
    }
  }, [status, pathname, router]);

  // Close mobile drawer on route change
  useEffect(() => {
    setMobileMenuOpen(false);
  }, [pathname]);

  const items = useMemo(() => visibleNavItems(NAV_ITEMS, user?.permissions ?? []), [user]);
  const allHrefs = useMemo(() => items.map((i) => i.href), [items]);

  if (status === "loading" || status === "unauthenticated") return <LoadingState label="Checking your session…" />;
  if (status === "error" || !user) return <ErrorState error={null} onRetry={() => window.location.reload()} />;

  const userInitials = user.name
    .split(" ")
    .map((n) => n[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  const primaryScope = user.scope.all_branches
    ? "All Branches"
    : user.scope.branches[0]?.name ?? "Branch Access";

  const renderNavList = () => (
    <nav aria-label="Main" className="flex flex-col gap-6 px-3 pt-4 pb-8">
      {NAV_SECTIONS.map((section) => {
        const sectionItems = items.filter((i) => i.section === section.key);
        if (sectionItems.length === 0) return null;
        return (
          <div key={section.key} className="flex flex-col gap-1">
            {section.label && (
              <span className="px-3 pb-1 text-[11px] font-bold uppercase tracking-wider text-muted/70">
                {section.label}
              </span>
            )}
            <div className="flex flex-col gap-0.5">
              {sectionItems.map((item) => {
                const active = isActivePath(pathname, item.href, allHrefs);
                const isUrgent = item.href === "/orders/urgent/";
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "group flex items-center justify-between rounded-xl px-3 py-2 text-xs font-medium transition-all duration-150 select-none",
                      active
                        ? "bg-brand text-white shadow-xs shadow-brand/30 font-semibold"
                        : "text-muted hover:bg-subtle hover:text-ink",
                    )}
                  >
                    <div className="flex items-center gap-2.5 truncate">
                      <span className={cn("shrink-0 transition-transform group-hover:scale-110", active ? "text-white" : "text-muted group-hover:text-ink")}>
                        {navIcon(item.href)}
                      </span>
                      <span className="truncate">{item.label}</span>
                    </div>
                    {isUrgent && (
                      <span className={cn("size-2 rounded-full", active ? "bg-white" : "bg-danger animate-pulse")} />
                    )}
                  </Link>
                );
              })}
            </div>
          </div>
        );
      })}
    </nav>
  );

  return (
    <div className="flex min-h-screen bg-canvas text-ink antialiased">
      {/* Desktop Sidebar */}
      <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-line bg-surface md:flex z-30">
        {/* Brand Header */}
        <div className="flex h-16 shrink-0 items-center justify-between border-b border-line px-5">
          <Link href="/dashboard/" className="flex items-center gap-2.5 group">
            <div className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-tr from-brand to-brand-strong text-white shadow-xs group-hover:scale-105 transition">
              <ShirtIcon size={18} />
            </div>
            <div>
              <div className="text-sm font-bold tracking-tight text-ink">Garment ERP</div>
              <div className="text-[10px] font-medium text-muted">Production Suite</div>
            </div>
          </Link>
          <span className="rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success-strong">
            Live
          </span>
        </div>

        {/* Navigation list */}
        <div className="flex-1 overflow-y-auto min-h-0">{renderNavList()}</div>

        {/* User profile footer */}
        <div className="border-t border-line bg-surface p-3 shrink-0">
          <div className="flex items-center justify-between gap-2 rounded-xl p-2 transition hover:bg-subtle">
            <Link href="/account/" className="flex items-center gap-2.5 min-w-0 flex-1">
              <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-xs font-bold text-brand">
                {userInitials}
              </div>
              <div className="min-w-0 flex-1">
                <div className="truncate text-xs font-semibold text-ink">{user.name}</div>
                <div className="truncate text-[10px] text-muted">{user.email}</div>
              </div>
            </Link>
            <button
              type="button"
              onClick={() => setConfirmLogout(true)}
              title="Sign out"
              aria-label="Sign out"
              className="rounded-lg p-1.5 text-muted hover:bg-danger-soft hover:text-danger transition cursor-pointer"
            >
              <LogOutIcon size={16} />
            </button>
          </div>
        </div>
      </aside>

      {/* Mobile Drawer Backdrop & Sidebar */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-xs md:hidden transition-opacity"
          onClick={() => setMobileMenuOpen(false)}
        />
      )}
      <div
        className={cn(
          "fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-surface border-r border-line shadow-2xl transition-transform duration-300 ease-in-out md:hidden",
          mobileMenuOpen ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <div className="flex h-16 shrink-0 items-center justify-between border-b border-line px-5">
          <div className="flex items-center gap-2.5">
            <div className="flex size-8 items-center justify-center rounded-xl bg-brand text-white">
              <ShirtIcon size={18} />
            </div>
            <div className="text-sm font-bold text-ink">Garment ERP</div>
          </div>
          <button
            type="button"
            onClick={() => setMobileMenuOpen(false)}
            aria-label="Close menu"
            className="rounded-lg p-1.5 text-muted hover:bg-subtle hover:text-ink cursor-pointer"
          >
            <XIcon size={18} />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto min-h-0">{renderNavList()}</div>
        <div className="border-t border-line bg-surface p-3 shrink-0">
          <div className="flex items-center justify-between gap-2 p-2">
            <Link href="/account/" className="flex items-center gap-2.5 min-w-0 flex-1">
              <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-xs font-bold text-brand">
                {userInitials}
              </div>
              <div className="min-w-0 flex-1 truncate text-xs font-semibold text-ink">{user.name}</div>
            </Link>
            <Button variant="ghost" size="sm" onClick={() => setConfirmLogout(true)}>
              Sign out
            </Button>
          </div>
        </div>
      </div>

      {/* Main Content Area */}
      <div className="flex flex-1 flex-col min-w-0">
        {/* Top Header */}
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-line bg-surface/80 px-4 md:px-8 backdrop-blur-md">
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setMobileMenuOpen(true)}
              aria-label="Open navigation menu"
              className="flex size-9 items-center justify-center rounded-lg border border-line text-muted hover:bg-subtle hover:text-ink md:hidden cursor-pointer"
            >
              <MenuIcon size={18} />
            </button>
            <div className="hidden sm:block text-xs font-medium text-muted">
              Colombo Time <span className="font-semibold text-ink">{new Date().toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", timeZone: "Asia/Colombo" })}</span>
            </div>
          </div>

          <div className="flex items-center gap-2 sm:gap-3">
            {/* Quick Action Button */}
            {items.some((i) => i.href === "/orders/new/") && (
              <Link
                href="/orders/new/"
                className="hidden sm:inline-flex h-8 items-center gap-1.5 rounded-lg bg-brand px-3 text-xs font-medium text-white shadow-xs hover:bg-brand-strong transition"
              >
                <PlusIcon size={14} />
                <span>New order</span>
              </Link>
            )}

            <NotificationBell />

            <div className="h-5 w-px bg-line" />

            <Link
              href="/account/"
              className="flex items-center gap-2 rounded-lg p-1 text-xs font-medium text-muted hover:text-ink transition"
            >
              <div className="flex size-7 items-center justify-center rounded-full bg-brand/10 text-[11px] font-bold text-brand">
                {userInitials}
              </div>
              <span className="hidden md:inline font-semibold text-ink">{user.name}</span>
            </Link>
          </div>
        </header>

        {/* Main page content container */}
        <main className="flex-1 px-4 py-6 md:px-8 max-w-7xl w-full mx-auto">{children}</main>
      </div>

      <ConfirmDialog
        open={confirmLogout}
        title="Sign out?"
        description="You will need to sign in again to access the production management system."
        confirmLabel="Sign out"
        tone="danger"
        loading={loggingOut}
        onCancel={() => setConfirmLogout(false)}
        onConfirm={async () => {
          setLoggingOut(true);
          await logout();
        }}
      />
    </div>
  );
}
