"use client";

import Link from "next/link";
import { PageHeader } from "@/components/ui/form-controls";
import { ChevronLeftIcon, PlusIcon, UsersIcon } from "@/components/ui/icons";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState } from "@/components/ui/states";
import { useAuth } from "@/features/auth/auth-provider";
import { CreateUserForm } from "./create-user-form";
import { UserDetail } from "./user-detail";
import { UsersTable } from "./users-table";

const linkButton = "inline-flex h-9 items-center gap-1.5 rounded-xl bg-brand px-4 text-xs font-semibold text-white shadow-xs hover:bg-brand-strong transition active:scale-[0.98]";

export function UsersScreen() {
  const { can } = useAuth();
  return (
    <RequirePermission anyOf={["users.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Staff User Accounts"
          description="Manage staff credentials, assigned roles, and company / page access boundaries."
          actions={
            can("users.manage") ? (
              <Link href="/settings/users/new/" className={linkButton}>
                <PlusIcon size={16} />
                <span>New user</span>
              </Link>
            ) : undefined
          }
        />
        <UsersTable />
      </div>
    </RequirePermission>
  );
}

export function NewUserScreen() {
  return (
    <RequirePermission anyOf={["users.manage"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Create New User"
          breadcrumb={
            <div className="flex items-center gap-1.5 text-xs text-muted">
              <Link href="/settings/users/" className="hover:text-ink">Users</Link>
              <span>/</span>
              <span className="font-semibold text-ink">New User</span>
            </div>
          }
          description="Provision a staff member account with defined permission scope."
          actions={
            <Link
              href="/settings/users/"
              className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-line bg-surface px-3 text-xs font-semibold text-muted shadow-xs hover:bg-subtle hover:text-ink transition"
            >
              <ChevronLeftIcon size={14} />
              <span>Back to users</span>
            </Link>
          }
        />
        <CreateUserForm />
      </div>
    </RequirePermission>
  );
}

export function UserScreen() {
  return (
    <RequirePermission anyOf={["users.view"]}>
      <WithIdParam>{(id) => (id === null ? <EmptyState title="User not found" /> : <UserDetail id={id} />)}</WithIdParam>
    </RequirePermission>
  );
}
