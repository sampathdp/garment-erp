"use client";

import { Checkbox } from "@/components/ui/form-controls";
import { BuildingIcon, GlobeIcon, ShieldIcon } from "@/components/ui/icons";
import { LoadingState } from "@/components/ui/states";
import { useBranches, usePages } from "@/features/organizations/api";
import { useRoles } from "@/features/roles/api";

/** Lists show only branches/pages the administrator can access; the API enforces the same rule. */
const ALL = { page: 1, per_page: 100, sort: "name" };

function toggle<T>(list: T[], value: T, on: boolean): T[] {
  return on ? (list.includes(value) ? list : [...list, value]) : list.filter((v) => v !== value);
}

export function RolePicker({ value, onChange, disabled }: { value: string[]; onChange: (roles: string[]) => void; disabled?: boolean }) {
  const roles = useRoles();
  if (roles.isPending) return <LoadingState label="Loading roles…" />;
  if (roles.error) return <p className="text-sm text-danger">Roles could not be loaded.</p>;

  return (
    <fieldset className="grid gap-3 sm:grid-cols-2" disabled={disabled}>
      <legend className="sr-only">Roles</legend>
      {roles.data.map((r) => {
        const isSelected = value.includes(r.name);
        return (
          <div
            key={r.name}
            className={`rounded-xl border p-3.5 transition-all ${
              isSelected
                ? "border-brand/40 bg-brand/5 shadow-xs"
                : "border-line bg-surface hover:border-line/90 hover:bg-subtle/30"
            }`}
          >
            <Checkbox
              label={
                <span className="flex items-center gap-2">
                  <ShieldIcon size={14} className={isSelected ? "text-brand" : "text-muted"} />
                  <span className="font-semibold text-ink">{r.label}</span>
                </span>
              }
              description={r.description ?? undefined}
              checked={isSelected}
              onChange={(e) => onChange(toggle(value, r.name, e.target.checked))}
            />
          </div>
        );
      })}
    </fieldset>
  );
}

export interface ScopeValue {
  branch_ids: number[];
  page_ids: number[];
  owner_page_ids: number[];
}

export function ScopePicker({ value, onChange, disabled }: { value: ScopeValue; onChange: (value: ScopeValue) => void; disabled?: boolean }) {
  const branches = useBranches(ALL);
  const pages = usePages(ALL);

  return (
    <div className="grid gap-6 md:grid-cols-2">
      <fieldset className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs" disabled={disabled}>
        <legend className="flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wider text-muted">
          <BuildingIcon size={14} className="text-brand" />
          Branch Assignments
        </legend>
        {branches.isPending && <LoadingState />}
        {branches.data?.data.length === 0 && <p className="text-xs text-muted">No branches available to you.</p>}
        <div className="flex flex-col gap-2">
          {branches.data?.data.map((b) => {
            const isAssigned = value.branch_ids.includes(b.id);
            return (
              <div
                key={b.id}
                className={`rounded-lg border px-3 py-2 transition-colors ${
                  isAssigned ? "border-brand/30 bg-brand/5" : "border-line/70 bg-surface hover:bg-subtle/40"
                }`}
              >
                <Checkbox
                  label={
                    <span className="flex items-center gap-2 text-xs">
                      <span className="font-medium text-ink">{b.name}</span>
                      <span className="font-mono text-[11px] text-muted bg-subtle px-1 rounded">{b.code}</span>
                    </span>
                  }
                  checked={isAssigned}
                  onChange={(e) => onChange({ ...value, branch_ids: toggle(value.branch_ids, b.id, e.target.checked) })}
                />
              </div>
            );
          })}
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs" disabled={disabled}>
        <legend className="flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wider text-muted">
          <GlobeIcon size={14} className="text-brand" />
          Page & Channel Scope
        </legend>
        {pages.isPending && <LoadingState />}
        {pages.data?.data.length === 0 && <p className="text-xs text-muted">No pages available to you.</p>}
        <div className="flex flex-col gap-2">
          {pages.data?.data.map((p) => {
            const member = value.page_ids.includes(p.id);
            const isOwner = value.owner_page_ids.includes(p.id);
            return (
              <div
                key={p.id}
                className={`flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 transition-colors ${
                  member ? "border-brand/30 bg-brand/5" : "border-line/70 bg-surface hover:bg-subtle/40"
                }`}
              >
                <Checkbox
                  label={
                    <span className="flex items-center gap-2 text-xs">
                      <span className="font-medium text-ink">{p.name}</span>
                      <span className="font-mono text-[11px] text-muted bg-subtle px-1 rounded">{p.code}</span>
                    </span>
                  }
                  checked={member}
                  onChange={(e) =>
                    onChange({
                      ...value,
                      page_ids: toggle(value.page_ids, p.id, e.target.checked),
                      owner_page_ids: e.target.checked ? value.owner_page_ids : value.owner_page_ids.filter((id) => id !== p.id),
                    })
                  }
                />
                <Checkbox
                  label={<span className="text-xs font-medium text-amber-700">Page Owner</span>}
                  disabled={!member || disabled}
                  checked={isOwner}
                  onChange={(e) => onChange({ ...value, owner_page_ids: toggle(value.owner_page_ids, p.id, e.target.checked) })}
                />
              </div>
            );
          })}
        </div>
      </fieldset>
    </div>
  );
}
