"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, TextAreaField } from "@/components/ui/form-controls";
import { PlusIcon, ShieldIcon } from "@/components/ui/icons";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { ApiError } from "@/lib/api-client";
import { type PermissionGroup, type Role, useDeleteRole, usePermissionCatalogue, useRole, useRoles, useSaveRole } from "./api";

const linkButton = "inline-flex h-9 items-center gap-1.5 rounded-xl bg-brand px-4 text-xs font-semibold text-white shadow-xs hover:bg-brand-strong transition active:scale-[0.98]";

export function RolesScreen() {
  const { can } = useAuth();
  const roles = useRoles();

  return (
    <RequirePermission anyOf={["roles.view", "roles.manage"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Staff Roles & Security Profiles"
          description="Roles bundle fine-grained permissions. Staff users are granted authorization through their assigned roles."
          actions={
            can("roles.manage") ? (
              <Link href="/settings/roles/view/" className={linkButton}>
                <PlusIcon size={16} />
                <span>New role</span>
              </Link>
            ) : undefined
          }
        />
        {roles.error && <ErrorState error={roles.error} onRetry={() => roles.refetch()} />}
        {roles.isPending && <LoadingState />}
        {roles.data && (
          <div className="grid gap-4 md:grid-cols-2">
            {roles.data.map((role) => (
              <div key={role.id} className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-5 shadow-xs transition hover:border-brand/40 hover:shadow-md">
                <div>
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line/60 pb-3">
                    <div className="flex items-center gap-2.5">
                      <div className="flex size-8 items-center justify-center rounded-lg bg-brand/10 text-brand">
                        <ShieldIcon size={16} />
                      </div>
                      <Link href={`/settings/roles/view/?id=${role.id}`} className="font-bold text-base text-brand hover:underline">
                        {role.label}
                      </Link>
                    </div>
                    <div className="flex items-center gap-1.5">
                      {role.is_locked && <Badge tone="danger" dot>Locked</Badge>}
                      {role.is_system && <Badge tone="neutral">System</Badge>}
                      <Badge tone="brand">{role.users_count} {role.users_count === 1 ? "user" : "users"}</Badge>
                    </div>
                  </div>
                  {role.description && <p className="mt-3 text-xs text-muted leading-relaxed">{role.description}</p>}
                </div>
                <div className="mt-4 flex items-center justify-between border-t border-line/50 pt-3 text-xs text-muted">
                  <span className="font-medium text-ink">{role.permissions.length} active permissions</span>
                  <Link href={`/settings/roles/view/?id=${role.id}`} className="font-semibold text-brand hover:underline">
                    Configure role →
                  </Link>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </RequirePermission>
  );
}

function PermissionMatrix({ groups, value, onChange, disabled, grantable }: { groups: PermissionGroup[]; value: string[]; onChange: (v: string[]) => void; disabled: boolean; grantable: (p: string) => boolean }) {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      {groups.map((group) => (
        <fieldset key={group.key} className="rounded-xl border border-line bg-surface p-4 shadow-xs" disabled={disabled}>
          <legend className="px-2 font-bold text-xs uppercase tracking-wider text-muted">{group.label}</legend>
          <div className="mt-3 flex flex-col gap-2.5">
            {group.permissions.map((p) => (
              <div key={p.name} className="flex items-start justify-between gap-2 rounded-lg bg-subtle/40 p-2 border border-line/40">
                <Checkbox
                  label={p.label}
                  description={<code className="font-mono text-[10px] text-muted">{p.name}</code>}
                  checked={value.includes(p.name)}
                  disabled={disabled || !grantable(p.name)}
                  onChange={(e) => onChange(e.target.checked ? [...value, p.name] : value.filter((v) => v !== p.name))}
                />
              </div>
            ))}
          </div>
        </fieldset>
      ))}
    </div>
  );
}

function RoleEditor({ role }: { role: Role | null }) {
  const router = useRouter();
  const { notify } = useToast();
  const { can } = useAuth();
  const catalogue = usePermissionCatalogue();
  const save = useSaveRole(role?.id ?? null);
  const remove = useDeleteRole();
  const [name, setName] = useState(role?.name ?? "");
  const [label, setLabel] = useState(role?.label ?? "");
  const [description, setDescription] = useState(role?.description ?? "");
  const [permissions, setPermissions] = useState<string[]>(role?.permissions ?? []);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const editable = role === null ? can("roles.manage") : role.abilities.update;
  const fieldErrors = save.error instanceof ApiError ? save.error.fieldErrors : {};
  const formError = save.error instanceof ApiError && !save.error.isValidation ? save.error.message : save.error && !(save.error instanceof ApiError) ? "Something went wrong." : null;

  const submit = () =>
    save.mutate(
      { ...(role?.is_system ? {} : { name }), label, description: description.trim() === "" ? null : description, permissions },
      {
        onSuccess: (saved) => {
          notify(role ? "Role saved. Changes apply to users on their next session request." : "Role created", "success");
          if (!role) router.replace(`/settings/roles/view/?id=${saved.id}`);
        },
      },
    );

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={role ? role.label : "New role"}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/settings/roles/" className="hover:text-ink">Roles</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{role ? role.label : "New Role"}</span>
          </div>
        }
        description={role?.is_locked ? "This role is locked and always holds every permission." : role ? `${role.users_count} users currently assigned this role.` : undefined}
        actions={
          role?.abilities.delete ? (
            <Button variant="danger" size="sm" onClick={() => setConfirmDelete(true)}>
              Delete role
            </Button>
          ) : undefined
        }
      />
      <FormError message={formError} />
      <Section title="Role Details" description="Define the system identifier key and human-friendly display name.">
        <fieldset disabled={!editable} className="grid gap-4 md:grid-cols-2">
          <TextField
            label="Key"
            value={name}
            onChange={(e) => setName(e.target.value)}
            maxLength={60}
            disabled={!editable || Boolean(role?.is_system)}
            hint={role?.is_system ? "System role keys cannot be changed." : "Lowercase letters, numbers and underscores, e.g. cutting_supervisor."}
            error={fieldErrors.name?.[0]}
          />
          <TextField label="Name" placeholder="e.g. Cutting Supervisor" value={label} onChange={(e) => setLabel(e.target.value)} maxLength={100} error={fieldErrors.label?.[0]} />
          <div className="md:col-span-2">
            <TextAreaField label="Description" placeholder="Explain the operational responsibilities of staff with this role…" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} error={fieldErrors.description?.[0]} />
          </div>
        </fieldset>
      </Section>
      <Section title="Granted Permissions" description="Data scope permissions determine what records are accessible; module permissions grant specific actions.">
        {catalogue.isPending && <LoadingState />}
        {catalogue.error && <ErrorState error={catalogue.error} />}
        {catalogue.data && <PermissionMatrix groups={catalogue.data} value={permissions} onChange={setPermissions} disabled={!editable} grantable={can} />}
        {fieldErrors.permissions && <p className="mt-3 text-xs font-semibold text-danger">{fieldErrors.permissions[0]}</p>}
      </Section>
      {editable && (
        <div className="flex justify-end pt-2">
          <Button loading={save.isPending} onClick={submit} disabled={label.trim() === "" || (!role && name.trim() === "")}>
            {role ? "Save role" : "Create role"}
          </Button>
        </div>
      )}
      {role && (
        <ConfirmDialog
          open={confirmDelete}
          tone="danger"
          title={`Delete ${role.label}?`}
          description="Only roles without any assigned users can be deleted. This action is irreversible."
          confirmLabel="Confirm Delete"
          loading={remove.isPending}
          onCancel={() => setConfirmDelete(false)}
          onConfirm={() =>
            remove.mutate(role.id, {
              onSuccess: () => {
                notify("Role deleted", "success");
                router.replace("/settings/roles/");
              },
              onError: (e) => {
                setConfirmDelete(false);
                notify(e instanceof ApiError ? e.message : "The role could not be deleted.", "error");
              },
            })
          }
        />
      )}
    </div>
  );
}

function ExistingRole({ id }: { id: number }) {
  const role = useRole(id);
  if (role.error) return <ErrorState error={role.error} onRetry={() => role.refetch()} />;
  if (role.isPending) return <LoadingState />;
  return <RoleEditor key={role.data.id} role={role.data} />;
}

export function RoleScreen() {
  return (
    <RequirePermission anyOf={["roles.view", "roles.manage"]}>
      <WithIdParam>{(id) => (id === null ? <NewRole /> : <ExistingRole id={id} />)}</WithIdParam>
    </RequirePermission>
  );
}

function NewRole() {
  const { can } = useAuth();
  return can("roles.manage") ? <RoleEditor role={null} /> : <EmptyState title="You cannot create roles" />;
}
