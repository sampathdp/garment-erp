"use client";

import Link from "next/link";
import { useState } from "react";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, SelectField, StatusBadge } from "@/components/ui/form-controls";
import { SearchIcon, UserIcon } from "@/components/ui/icons";
import { useRoles } from "@/features/roles/api";
import { useAuth } from "@/features/auth/auth-provider";
import { formatDateTime } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { type UserListParams, type UserRow, useUsers } from "./api";

const columns: Column<UserRow>[] = [
  {
    key: "name",
    header: "Staff Member",
    sortKey: "name",
    cell: (u) => {
      const initials = u.name
        .split(" ")
        .map((n) => n[0])
        .slice(0, 2)
        .join("")
        .toUpperCase();
      return (
        <div className="flex items-center gap-2.5">
          <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand/10 text-[11px] font-bold text-brand">
            {initials}
          </div>
          <Link href={`/settings/users/view/?id=${u.id}`} className="font-semibold text-brand hover:underline">
            {u.name}
          </Link>
        </div>
      );
    },
  },
  { key: "email", header: "Email Address", sortKey: "email", cell: (u) => <span className="text-xs text-muted font-mono">{u.email}</span> },
  {
    key: "roles",
    header: "Assigned Roles",
    cell: (u) => (
      <span className="flex flex-wrap gap-1">
        {u.roles.map((r) => (
          <Badge key={r.name} tone="brand">
            {r.label}
          </Badge>
        ))}
      </span>
    ),
  },
  {
    key: "pages",
    header: "Authorized Channels",
    cell: (u) => (
      <div className="flex flex-wrap gap-1">
        {u.pages.length === 0 ? (
          <span className="text-xs text-muted">—</span>
        ) : (
          u.pages.map((p) => (
            <span key={p.code} className="inline-flex items-center gap-1 rounded bg-subtle px-1.5 py-0.5 text-[11px] font-medium text-ink">
              {p.code}
              {p.is_owner && <span className="text-brand font-bold">★</span>}
            </span>
          ))
        )}
      </div>
    ),
  },
  { key: "status", header: "Status", cell: (u) => <StatusBadge active={u.is_active} /> },
  { key: "last_login", header: "Last Active", sortKey: "last_login_at", cell: (u) => <span className="text-xs text-muted">{formatDateTime(u.last_login_at)}</span> },
];

export function UsersTable() {
  const { can } = useAuth();
  const [params, setParams] = useState<UserListParams>({ page: 1, sort: "name", search: "", is_active: "", role: "" });
  const query = useUsers(useDebounced(params));
  const roles = useRoles(can("roles.view") || can("roles.manage"));

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center">
        <div className="flex-1">
          <TextField
            label="Search staff"
            placeholder="Search by name or email…"
            icon={<SearchIcon size={16} />}
            maxLength={100}
            value={params.search}
            onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
          />
        </div>
        <div className="w-full sm:w-44">
          <SelectField
            label="Account status"
            value={params.is_active}
            onChange={(e) => setParams((p) => ({ ...p, page: 1, is_active: e.target.value as UserListParams["is_active"] }))}
            options={[
              { value: "", label: "All statuses" },
              { value: "1", label: "Active" },
              { value: "0", label: "Inactive" },
            ]}
          />
        </div>
        <div className="w-full sm:w-56">
          <SelectField
            label="Role filter"
            value={params.role}
            onChange={(e) => setParams((p) => ({ ...p, page: 1, role: e.target.value }))}
            options={[{ value: "", label: "All roles" }, ...(roles.data ?? []).map((r) => ({ value: r.name, label: r.label }))]}
          />
        </div>
      </div>
      <DataTable
        caption="Users"
        columns={columns}
        result={query.data}
        isLoading={query.isPending}
        error={query.error}
        onRetry={() => query.refetch()}
        rowKey={(u) => u.id}
        sort={params.sort}
        onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
        onPageChange={(page) => setParams((p) => ({ ...p, page }))}
        emptyTitle="No staff users found"
        emptyDescription="Try clearing your search or filters to see all users."
      />
    </div>
  );
}
