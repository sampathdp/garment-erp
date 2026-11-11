"use client";

import Link from "next/link";
import {
  AlertTriangleIcon,
  BoxesIcon,
  BuildingIcon,
  CheckCircleIcon,
  ClockIcon,
  CreditCardIcon,
  GlobeIcon,
  PaletteIcon,
  PlusIcon,
  ShieldIcon,
  ShirtIcon,
  ShoppingBagIcon,
  UsersIcon,
} from "@/components/ui/icons";
import { Badge } from "@/components/ui/form-controls";
import { useAuth } from "@/features/auth/auth-provider";

export function WelcomePanel() {
  const { user, can } = useAuth();
  if (!user) return null;

  const currentHour = new Date().getHours();
  const greeting =
    currentHour < 12 ? "Good morning" : currentHour < 17 ? "Good afternoon" : "Good evening";

  const branches = user.scope.all_branches
    ? ["All active branches"]
    : user.scope.branches.map((b) => b.name);

  const pages = user.scope.all_pages
    ? ["All Facebook sales pages"]
    : user.scope.pages.map((p) => p.name);

  return (
    <div className="flex flex-col gap-8">
      {/* Hero Welcome Card */}
      <div className="relative overflow-hidden rounded-2xl border border-line bg-gradient-to-br from-surface via-surface to-brand-soft/30 p-6 md:p-8 shadow-sm">
        <div className="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-brand/5 blur-2xl" />
        <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-col gap-1.5">
            <div className="flex items-center gap-2">
              <span className="text-xs font-bold uppercase tracking-wider text-brand">Staff Dashboard</span>
              <span className="size-1 rounded-full bg-muted" />
              <span className="text-xs text-muted">Asia/Colombo</span>
            </div>
            <h1 className="text-2xl md:text-3xl font-extrabold tracking-tight text-ink">
              {greeting}, {user.name}!
            </h1>
            <p className="text-sm text-muted max-w-xl">
              Welcome to the Garment Production Management workspace. Track active orders, verify advance deposits, oversee urgent approvals, and monitor design queues.
            </p>
          </div>

          {can("orders.create") && (
            <div className="shrink-0">
              <Link
                href="/orders/new/"
                className="inline-flex h-11 items-center gap-2 rounded-xl bg-brand px-5 text-sm font-semibold text-white shadow-md shadow-brand/25 hover:bg-brand-strong transition active:scale-[0.98]"
              >
                <PlusIcon size={18} />
                <span>Create New Order</span>
              </Link>
            </div>
          )}
        </div>
      </div>

      {/* KPI & Fast Action Shortcuts */}
      <div>
        <h2 className="mb-4 text-xs font-bold uppercase tracking-wider text-muted">
          Quick Access & Operational Pipelines
        </h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {/* Card 1: Orders */}
          <Link
            href="/orders/"
            className="group flex flex-col justify-between rounded-xl border border-line bg-surface p-5 shadow-xs transition hover:border-brand/40 hover:shadow-md"
          >
            <div className="flex items-start justify-between">
              <div className="flex size-11 items-center justify-center rounded-xl bg-brand/10 text-brand group-hover:scale-110 transition">
                <ShoppingBagIcon size={22} />
              </div>
              <span className="text-xs font-semibold text-brand group-hover:translate-x-0.5 transition">View all →</span>
            </div>
            <div className="mt-4">
              <h3 className="text-sm font-bold text-ink">Active Orders</h3>
              <p className="mt-0.5 text-xs text-muted">Browse all customer jobs, delivery schedules, and statuses.</p>
            </div>
          </Link>

          {/* Card 2: Urgent Approvals */}
          <Link
            href="/orders/urgent/"
            className="group flex flex-col justify-between rounded-xl border border-danger/30 bg-surface p-5 shadow-xs transition hover:border-danger hover:shadow-md"
          >
            <div className="flex items-start justify-between">
              <div className="flex size-11 items-center justify-center rounded-xl bg-danger/10 text-danger group-hover:scale-110 transition">
                <AlertTriangleIcon size={22} />
              </div>
              <Badge tone="danger" dot>Action Required</Badge>
            </div>
            <div className="mt-4">
              <h3 className="text-sm font-bold text-ink">Urgent Approvals</h3>
              <p className="mt-0.5 text-xs text-muted">Jobs due in &lt; 7 days needing Second Admin & Operations sign-off.</p>
            </div>
          </Link>

          {/* Card 3: Payment Verification */}
          <Link
            href="/payments/"
            className="group flex flex-col justify-between rounded-xl border border-line bg-surface p-5 shadow-xs transition hover:border-brand/40 hover:shadow-md"
          >
            <div className="flex items-start justify-between">
              <div className="flex size-11 items-center justify-center rounded-xl bg-success-soft text-success-strong group-hover:scale-110 transition">
                <CreditCardIcon size={22} />
              </div>
              <span className="text-xs font-semibold text-muted group-hover:text-ink transition">Verify →</span>
            </div>
            <div className="mt-4">
              <h3 className="text-sm font-bold text-ink">Payment Queue</h3>
              <p className="mt-0.5 text-xs text-muted">Verify receipts & bank slips to meet the 30% advance rule.</p>
            </div>
          </Link>

          {/* Card 4: Design Plan */}
          <Link
            href="/designs/"
            className="group flex flex-col justify-between rounded-xl border border-line bg-surface p-5 shadow-xs transition hover:border-brand/40 hover:shadow-md"
          >
            <div className="flex items-start justify-between">
              <div className="flex size-11 items-center justify-center rounded-xl bg-brand/10 text-brand group-hover:scale-110 transition">
                <PaletteIcon size={22} />
              </div>
              <span className="text-xs font-semibold text-muted group-hover:text-ink transition">Queue →</span>
            </div>
            <div className="mt-4">
              <h3 className="text-sm font-bold text-ink">Design Plan</h3>
              <p className="mt-0.5 text-xs text-muted">Review artwork drafts, customer corrections, and final files.</p>
            </div>
          </Link>
        </div>
      </div>

      {/* Production Pipeline Overview */}
      <div className="rounded-xl border border-line bg-surface p-6 shadow-xs">
        <h2 className="text-sm font-bold text-ink mb-1">Standard Production Workflow</h2>
        <p className="text-xs text-muted mb-6">Each garment job moves systematically through verified quality checkpoints:</p>
        <div className="grid gap-3 sm:grid-cols-5 text-center">
          <div className="flex flex-col items-center gap-2 rounded-xl bg-subtle/50 p-4 border border-line/60">
            <span className="flex size-8 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">1</span>
            <span className="text-xs font-semibold text-ink">Order Intake</span>
            <span className="text-[11px] text-muted">Customer & specs entered</span>
          </div>
          <div className="flex flex-col items-center gap-2 rounded-xl bg-subtle/50 p-4 border border-line/60">
            <span className="flex size-8 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">2</span>
            <span className="text-xs font-semibold text-ink">30% Advance</span>
            <span className="text-[11px] text-muted">Payment verified by 2nd staff</span>
          </div>
          <div className="flex flex-col items-center gap-2 rounded-xl bg-subtle/50 p-4 border border-line/60">
            <span className="flex size-8 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">3</span>
            <span className="text-xs font-semibold text-ink">Artwork Approval</span>
            <span className="text-[11px] text-muted">Page Owner locks design</span>
          </div>
          <div className="flex flex-col items-center gap-2 rounded-xl bg-subtle/50 p-4 border border-line/60">
            <span className="flex size-8 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">4</span>
            <span className="text-xs font-semibold text-ink">Bulk Production</span>
            <span className="text-[11px] text-muted">Printing, cutting & sewing</span>
          </div>
          <div className="flex flex-col items-center gap-2 rounded-xl bg-subtle/50 p-4 border border-line/60">
            <span className="flex size-8 items-center justify-center rounded-full bg-success-soft text-xs font-bold text-success-strong">5</span>
            <span className="text-xs font-semibold text-ink">Gate Pass & Delivery</span>
            <span className="text-[11px] text-muted">Dispatch & balance settling</span>
          </div>
        </div>
      </div>

      {/* Scope Info: Companies & Shops */}
      <div className="rounded-xl border border-line bg-surface p-6 shadow-xs">
        <div className="flex items-center gap-2.5 mb-4 border-b border-line/60 pb-3">
          <div className="flex size-8 items-center justify-center rounded-lg bg-brand/10 text-brand">
            <GlobeIcon size={16} />
          </div>
          <div>
            <h2 className="text-sm font-bold text-ink">Authorized Companies & Shops</h2>
            <p className="text-xs text-muted">Store and sales pipelines you are authorized to manage</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {pages.length > 0 ? (
            pages.map((p, idx) => (
              <span
                key={idx}
                className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-subtle px-3 py-1.5 text-xs font-medium text-ink"
              >
                <span className="size-1.5 rounded-full bg-brand" />
                {p}
              </span>
            ))
          ) : (
            <span className="text-xs text-muted">No specific companies or shops assigned.</span>
          )}
        </div>
      </div>
    </div>
  );
}
