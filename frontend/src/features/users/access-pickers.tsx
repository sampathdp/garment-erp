"use client";

import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Checkbox, SelectField } from "@/components/ui/form-controls";
import {
  CheckCircleIcon,
  GlobeIcon,
  PlusIcon,
  SearchIcon,
  ShieldIcon,
  StarIcon,
  XIcon,
} from "@/components/ui/icons";
import { LoadingState } from "@/components/ui/states";
import { usePages } from "@/features/organizations/api";
import { useRoles, type Role } from "@/features/roles/api";

const ALL = { page: 1, per_page: 100, sort: "name" };

const EXCLUDED_ROLE_NAMES = new Set([
  "accounts",
  "branch_manager",
  "cutting_supervisor",
  "delivery_coordinator",
  "delivery_staff",
  "design_lead",
  "designer",
  "gate_keeper",
  "owner",
  "page_owner",
  "printing_operator",
  "qc_inspector",
  "sales",
  "sewing_supervisor",
]);

function toggle<T>(list: T[], value: T, on: boolean): T[] {
  return on ? (list.includes(value) ? list : [...list, value]) : list.filter((v) => v !== value);
}

export function RolePicker({
  value,
  onChange,
  disabled,
}: {
  value: string[];
  onChange: (roles: string[]) => void;
  disabled?: boolean;
}) {
  const roles = useRoles();
  const [addingAdditional, setAddingAdditional] = useState(false);

  if (roles.isPending) return <LoadingState label="Loading roles…" />;
  if (roles.error) return <p className="text-sm text-danger">Roles could not be loaded.</p>;

  const roleList = (roles.data ?? []).filter((r) => !EXCLUDED_ROLE_NAMES.has(r.name));
  const primaryRole = value[0] ?? "";
  const additionalRoles = value.slice(1);
  const selectedRoleObjs = value
    .map((name) => roleList.find((r) => r.name === name))
    .filter((r): r is Role => Boolean(r));

  const availableForAdditional = roleList.filter((r) => !value.includes(r.name));

  const handlePrimaryChange = (newRole: string) => {
    if (!newRole) {
      onChange([]);
    } else {
      onChange([newRole, ...additionalRoles]);
    }
  };

  const handleAddAdditional = (newRole: string) => {
    if (!newRole) return;
    if (!value.includes(newRole)) {
      onChange([...value, newRole]);
    }
    setAddingAdditional(false);
  };

  const handleRemoveRole = (roleNameToRemove: string) => {
    onChange(value.filter((name) => name !== roleNameToRemove));
  };

  return (
    <div className="flex flex-col gap-3">
      {/* Primary Role Dropdown */}
      <SelectField
        label="Select Role *"
        disabled={disabled}
        value={primaryRole}
        onChange={(e) => handlePrimaryChange(e.target.value)}
        options={[
          { value: "", label: "-- Select Role --" },
          ...roleList.map((r) => ({
            value: r.name,
            label: `${r.label}${r.is_system ? " (System)" : ""}`,
          })),
        ]}
      />

      {/* Selected Role Detail Preview */}
      {selectedRoleObjs.length > 0 && (
        <div className="flex flex-col gap-2 pt-1">
          {selectedRoleObjs.map((r, idx) => (
            <div
              key={r.name}
              className="flex items-start justify-between gap-3 rounded-xl border border-brand/30 bg-brand/5 p-3.5 shadow-xs"
            >
              <div className="flex items-start gap-2.5">
                <div className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-brand text-white shadow-xs">
                  <ShieldIcon size={14} />
                </div>
                <div className="flex flex-col">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs font-bold text-ink">{r.label}</span>
                    {idx === 0 && (
                      <span className="inline-flex items-center rounded-sm bg-brand/15 text-brand px-1.5 py-0.2 text-[10px] font-semibold uppercase">
                        Primary
                      </span>
                    )}
                    {r.is_system && (
                      <span className="inline-flex items-center rounded-sm bg-line/60 px-1.5 py-0.2 text-[10px] font-semibold uppercase text-muted">
                        System
                      </span>
                    )}
                  </div>
                  {r.description && (
                    <p className="mt-0.5 text-[11px] leading-relaxed text-muted">
                      {r.description}
                    </p>
                  )}
                  <span className="mt-1 text-[10px] font-medium text-muted">
                    Grants {r.permissions.length} system permissions
                  </span>
                </div>
              </div>

              {!disabled && (
                <button
                  type="button"
                  onClick={() => handleRemoveRole(r.name)}
                  className="rounded-lg p-1 text-muted hover:bg-subtle hover:text-danger transition"
                  title={`Remove ${r.label}`}
                >
                  <XIcon size={14} />
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Assign an Additional Role */}
      {!disabled && availableForAdditional.length > 0 && primaryRole && (
        <div className="pt-1">
          {addingAdditional ? (
            <div className="flex items-end gap-2">
              <div className="flex-1">
                <SelectField
                  label="Add Additional Role"
                  value=""
                  onChange={(e) => handleAddAdditional(e.target.value)}
                  options={[
                    { value: "", label: "-- Choose additional role to grant --" },
                    ...availableForAdditional.map((r) => ({
                      value: r.name,
                      label: `${r.label}${r.is_system ? " (System)" : ""}`,
                    })),
                  ]}
                />
              </div>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => setAddingAdditional(false)}
                className="mb-0.5"
              >
                Cancel
              </Button>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => setAddingAdditional(true)}
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand hover:underline"
            >
              <PlusIcon size={13} />
              <span>Assign an additional role</span>
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export interface ScopeValue {
  branch_ids: number[];
  page_ids: number[];
  owner_page_ids: number[];
}

export function ScopePicker({
  value,
  onChange,
  disabled,
}: {
  value: ScopeValue;
  onChange: (value: ScopeValue) => void;
  disabled?: boolean;
}) {
  const pages = usePages(ALL);
  const [search, setSearch] = useState("");

  const pageList = pages.data?.data ?? [];

  const filteredPages = useMemo(() => {
    if (!search.trim()) return pageList;
    const query = search.toLowerCase();
    return pageList.filter(
      (p) =>
        p.name.toLowerCase().includes(query) ||
        p.code.toLowerCase().includes(query),
    );
  }, [pageList, search]);

  const allPageIds = pageList.map((p) => p.id);
  const allSelected = allPageIds.length > 0 && allPageIds.every((id) => value.page_ids.includes(id));

  const selectAllPages = () =>
    onChange({
      ...value,
      page_ids: Array.from(new Set([...value.page_ids, ...allPageIds])),
    });

  const clearAllPages = () =>
    onChange({
      ...value,
      page_ids: [],
      owner_page_ids: [],
    });

  return (
    <div className="flex flex-col gap-4">
      {/* Scope Toolbar */}
      <div className="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative flex-1 max-w-sm">
          <SearchIcon
            size={14}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
          />
          <input
            type="text"
            placeholder="Filter companies & stores…"
            value={search}
            disabled={disabled}
            onChange={(e) => setSearch(e.target.value)}
            className="h-8.5 w-full rounded-lg border border-line bg-surface pl-8.5 pr-8 text-xs text-ink placeholder:text-muted focus:border-brand focus:outline-hidden focus:ring-1 focus:ring-brand/40 disabled:opacity-60"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch("")}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-ink"
            >
              <XIcon size={13} />
            </button>
          )}
        </div>

        <div className="flex items-center gap-2 text-xs">
          <span className="inline-flex items-center rounded-md bg-subtle px-2 py-0.5 font-medium text-muted">
            <span className="font-semibold text-ink mr-1">{value.page_ids.length}</span> of{" "}
            {pageList.length} authorized
          </span>
          {!disabled && (
            <>
              <span className="text-muted/40">|</span>
              <button
                type="button"
                onClick={allSelected ? clearAllPages : selectAllPages}
                className="font-semibold text-brand hover:underline"
              >
                {allSelected ? "Clear all" : "Authorize all"}
              </button>
            </>
          )}
        </div>
      </div>

      {pages.isPending && <LoadingState label="Loading company stores…" />}
      {pageList.length === 0 && !pages.isPending && (
        <p className="rounded-xl border border-dashed border-line p-6 text-center text-xs text-muted">
          No companies or pages available in your catalog.
        </p>
      )}

      {/* Company Store Rows */}
      <fieldset className="flex flex-col gap-2.5" disabled={disabled}>
        <legend className="sr-only">Company and page access</legend>
        {filteredPages.map((p) => {
          const isMember = value.page_ids.includes(p.id);
          const isOwner = value.owner_page_ids.includes(p.id);

          return (
            <div
              key={p.id}
              className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3.5 transition-all duration-150 ${
                isMember
                  ? "border-brand/35 bg-brand/5 shadow-xs"
                  : "border-line bg-surface hover:border-line-strong hover:bg-subtle/30"
              }`}
            >
              {/* Store Identity & Access Checkbox */}
              <div className="flex items-center gap-3">
                <Checkbox
                  label=""
                  aria-label={`Authorize access to ${p.name}`}
                  checked={isMember}
                  disabled={disabled}
                  onChange={(e) =>
                    onChange({
                      ...value,
                      page_ids: toggle(value.page_ids, p.id, e.target.checked),
                      owner_page_ids: e.target.checked
                        ? value.owner_page_ids
                        : value.owner_page_ids.filter((id) => id !== p.id),
                    })
                  }
                />
                <div
                  className="flex cursor-pointer items-center gap-2.5"
                  onClick={() =>
                    !disabled &&
                    onChange({
                      ...value,
                      page_ids: toggle(value.page_ids, p.id, !isMember),
                      owner_page_ids: !isMember
                        ? value.owner_page_ids
                        : value.owner_page_ids.filter((id) => id !== p.id),
                    })
                  }
                >
                  <div className="flex size-7 items-center justify-center rounded-lg bg-brand/10 text-brand">
                    <GlobeIcon size={14} />
                  </div>
                  <div className="flex flex-col">
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-ink">{p.name}</span>
                      <span className="font-mono text-[10px] font-semibold text-muted bg-subtle px-1.5 py-0.5 rounded border border-line">
                        {p.code}
                      </span>
                    </div>
                    <span className="text-[11px] text-muted">
                      {isMember ? "Authorized for order management" : "No access granted"}
                    </span>
                  </div>
                </div>
              </div>

              {/* Page Owner Toggle Control */}
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={!isMember || disabled}
                  onClick={() =>
                    onChange({
                      ...value,
                      owner_page_ids: toggle(value.owner_page_ids, p.id, !isOwner),
                    })
                  }
                  className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition-all ${
                    !isMember
                      ? "cursor-not-allowed opacity-40 border border-line bg-subtle text-muted"
                      : isOwner
                        ? "border border-amber-500/40 bg-amber-500/15 text-amber-500 shadow-xs ring-1 ring-amber-500/20"
                        : "border border-line bg-surface text-muted hover:border-amber-500/30 hover:text-amber-500 hover:bg-amber-500/5"
                  }`}
                  title={
                    isMember
                      ? "Page owners can approve artwork, lock design files, and sign off urgent orders."
                      : "Authorize page access first to designate as Page Owner"
                  }
                >
                  <StarIcon size={13} className={isOwner ? "fill-amber-500 text-amber-500" : "text-muted"} />
                  <span>{isOwner ? "Page Owner" : "Make Owner"}</span>
                </button>
              </div>
            </div>
          );
        })}

        {filteredPages.length === 0 && pageList.length > 0 && (
          <div className="rounded-xl border border-dashed border-line p-6 text-center text-xs text-muted">
            No companies or stores match &quot;{search}&quot;.
          </div>
        )}
      </fieldset>
    </div>
  );
}
