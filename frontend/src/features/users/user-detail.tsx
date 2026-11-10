"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, StatusBadge, TextAreaField } from "@/components/ui/form-controls";
import { Modal } from "@/components/ui/modal";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { ApiError } from "@/lib/api-client";
import { formatDateTime } from "@/lib/format";
import { applyServerErrors } from "@/lib/forms";
import { RolePicker, ScopePicker, type ScopeValue } from "./access-pickers";
import { describeAction, type UserRow, useUser, useUserActivity, useUserMutations } from "./api";

const detailsSchema = z.object({
  name: z.string().trim().min(2, "Enter the full name").max(120),
  email: z.string().trim().min(1, "Email is required").max(191).email("Enter a valid email"),
  phone: z.string().trim().max(20),
});

const errorMessage = (error: unknown) => (error instanceof ApiError ? error.message : "Something went wrong. Please try again.");

function DetailsForm({ user, editable }: { user: UserRow; editable: boolean }) {
  const { updateDetails } = useUserMutations(user.id);
  const { notify } = useToast();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<z.infer<typeof detailsSchema>>({ resolver: zodResolver(detailsSchema), defaultValues: { name: user.name, email: user.email, phone: user.phone ?? "" } });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    try {
      const updated = await updateDetails.mutateAsync({ ...values, phone: values.phone === "" ? null : values.phone });
      form.reset({ name: updated.name, email: updated.email, phone: updated.phone ?? "" });
      notify(values.email !== user.email ? "Saved. The user was signed out because the email changed." : "Saved", "success");
    } catch (error) {
      setFormError(applyServerErrors(error, form.setError, ["name", "email", "phone"]));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <FormError message={formError} />
      <fieldset disabled={!editable} className="grid gap-4 md:grid-cols-3">
        <TextField label="Full name" maxLength={120} error={form.formState.errors.name?.message} {...form.register("name")} />
        <TextField label="Email" type="email" maxLength={191} hint="Changing it signs the user out." error={form.formState.errors.email?.message} {...form.register("email")} />
        <TextField label="Phone" type="tel" maxLength={20} error={form.formState.errors.phone?.message} {...form.register("phone")} />
      </fieldset>
      {editable && (
        <div>
          <Button type="submit" loading={form.formState.isSubmitting} disabled={!form.formState.isDirty}>
            Save details
          </Button>
        </div>
      )}
    </form>
  );
}

function RolesEditor({ user, editable }: { user: UserRow; editable: boolean }) {
  const { setRoles } = useUserMutations(user.id);
  const { notify } = useToast();
  // Re-mounted (key) when the saved roles change, so local state starts from the server's answer.
  const [roles, setLocal] = useState(() => user.roles.map((r) => r.name));

  const dirty = roles.slice().sort().join() !== user.roles.map((r) => r.name).sort().join();
  return (
    <div className="flex flex-col gap-4">
      <RolePicker value={roles} onChange={setLocal} disabled={!editable} />
      {setRoles.error && <FormError message={errorMessage(setRoles.error)} />}
      {editable && (
        <div>
          <Button disabled={!dirty} loading={setRoles.isPending} onClick={() => setRoles.mutate(roles, { onSuccess: () => notify("Roles updated. They apply on the user's next request.", "success") })}>
            Save roles
          </Button>
        </div>
      )}
    </div>
  );
}

function AssignmentsEditor({ user, editable }: { user: UserRow; editable: boolean }) {
  const { setAssignments } = useUserMutations(user.id);
  const { notify } = useToast();
  const fromUser = (u: UserRow): ScopeValue => ({ branch_ids: u.branches.map((b) => b.id), page_ids: u.pages.map((p) => p.id), owner_page_ids: u.pages.filter((p) => p.is_owner).map((p) => p.id) });
  const [scope, setScope] = useState<ScopeValue>(() => fromUser(user));

  return (
    <div className="flex flex-col gap-4">
      <ScopePicker value={scope} onChange={setScope} disabled={!editable} />
      {setAssignments.error && <FormError message={errorMessage(setAssignments.error)} />}
      {editable && (
        <div>
          <Button loading={setAssignments.isPending} onClick={() => setAssignments.mutate(scope, { onSuccess: () => notify("Assignments updated", "success") })}>
            Save assignments
          </Button>
        </div>
      )}
    </div>
  );
}

function AccountActions({ user }: { user: UserRow }) {
  const { setStatus, sendPasswordReset } = useUserMutations(user.id);
  const { notify } = useToast();
  const [deactivateOpen, setDeactivateOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [resetOpen, setResetOpen] = useState(false);
  const [signOut, setSignOut] = useState(true);
  const neverSignedIn = user.last_login_at === null;

  return (
    <div className="flex flex-wrap gap-2">
      {user.is_active ? (
        <Button variant="danger" onClick={() => setDeactivateOpen(true)}>
          Deactivate account
        </Button>
      ) : (
        <Button loading={setStatus.isPending} onClick={() => setStatus.mutate({ is_active: true, reason: null }, { onSuccess: () => notify("Account activated", "success"), onError: (e) => notify(errorMessage(e), "error") })}>
          Activate account
        </Button>
      )}
      <Button variant="secondary" onClick={() => setResetOpen(true)} disabled={!user.is_active}>
        {neverSignedIn ? "Resend setup link" : "Send password reset link"}
      </Button>

      <Modal open={deactivateOpen} title={`Deactivate ${user.name}?`} onClose={() => setDeactivateOpen(false)}>
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted">The user is signed out everywhere immediately and cannot sign in until reactivated. Their history is kept.</p>
          <TextAreaField label="Reason" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          {setStatus.error && <FormError message={errorMessage(setStatus.error)} />}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeactivateOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              disabled={reason.trim() === ""}
              loading={setStatus.isPending}
              onClick={() =>
                setStatus.mutate(
                  { is_active: false, reason: reason.trim() },
                  {
                    onSuccess: () => {
                      setDeactivateOpen(false);
                      setReason("");
                      notify("Account deactivated", "success");
                    },
                  },
                )
              }
            >
              Deactivate
            </Button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={resetOpen}
        title={neverSignedIn ? "Resend the account setup link?" : "Send a password reset link?"}
        description={
          <div className="flex flex-col gap-3">
            <p>The user receives a single-use link valid for 60 minutes. You never see or choose their password.</p>
            <Checkbox label="Also sign the user out on all devices now" checked={signOut} onChange={(e) => setSignOut(e.target.checked)} />
          </div>
        }
        confirmLabel="Send link"
        loading={sendPasswordReset.isPending}
        onCancel={() => setResetOpen(false)}
        onConfirm={() =>
          sendPasswordReset.mutate(signOut, {
            onSuccess: () => {
              setResetOpen(false);
              notify("Link sent", "success");
            },
            onError: (e) => notify(errorMessage(e), "error"),
          })
        }
      />
    </div>
  );
}

function ActivityLog({ userId }: { userId: number }) {
  const [page, setPage] = useState(1);
  const query = useUserActivity(userId, page, true);

  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  if (query.isPending) return <LoadingState />;
  if (query.data.data.length === 0) return <EmptyState title="No activity recorded yet" />;

  return (
    <div className="flex flex-col gap-4">
      <ol className="relative border-l border-line/70 ml-3 space-y-4">
        {query.data.data.map((entry) => (
          <li key={entry.id} className="ml-6 relative">
            <span className="absolute -left-[31px] top-1 flex h-3 w-3 items-center justify-center rounded-full bg-brand ring-4 ring-white" />
            <div className="rounded-xl border border-line bg-surface p-3 shadow-xs">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="font-semibold text-xs text-ink">{describeAction(entry.action)}</span>
                <span className="font-mono text-[11px] text-muted">
                  {formatDateTime(entry.occurred_at)}
                </span>
              </div>
              <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                {entry.actor && entry.actor.id !== userId && (
                  <span className="rounded bg-subtle px-1.5 py-0.5 text-[11px] font-medium text-ink">
                    Action by {entry.actor.name ?? `#${entry.actor.id}`}
                  </span>
                )}
                {entry.ip && <span>IP: {entry.ip}</span>}
              </div>
            </div>
          </li>
        ))}
      </ol>
      {query.data.meta.last_page > 1 && (
        <div className="flex justify-end gap-2 pt-2">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Newer
          </Button>
          <Button variant="secondary" size="sm" disabled={page >= query.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
            Older
          </Button>
        </div>
      )}
    </div>
  );
}

export function UserDetail({ id }: { id: number }) {
  const { user: me } = useAuth();
  const query = useUser(id);

  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  if (query.isPending) return <LoadingState />;

  const user = query.data;
  const abilities = user.abilities ?? { update: false, administer: false, view_activity: false };
  const self = me?.id === user.id;

  const initials = user.name
    .split(/\s+/)
    .map((p) => p[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase();

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={user.name}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <StatusBadge active={user.is_active} /> Last sign-in {formatDateTime(user.last_login_at)}
            {user.deactivated_at && ` · deactivated ${formatDateTime(user.deactivated_at)}`}
          </span>
        }
        actions={abilities.administer ? <AccountActions user={user} /> : undefined}
      />

      {/* Staff Hero Banner */}
      <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-line bg-gradient-to-r from-surface to-subtle/50 p-5 shadow-xs">
        <div className="flex items-center gap-4">
          <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-lg font-bold text-white shadow-sm">
            {initials}
          </div>
          <div className="flex flex-col gap-1">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-bold tracking-tight text-ink">{user.name}</h2>
              <StatusBadge active={user.is_active} />
              {self && <Badge tone="brand">You</Badge>}
            </div>
            <div className="flex flex-wrap items-center gap-3 text-xs text-muted">
              <span>{user.email}</span>
              {user.phone && <span>· {user.phone}</span>}
            </div>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          {user.roles.map((r) => (
            <Badge key={r.name} tone="brand">{r.label}</Badge>
          ))}
        </div>
      </div>

      {self && (
        <div className="rounded-xl border border-blue-200 bg-blue-50/50 p-4 text-xs text-blue-900">
          This is your personal account. Use <strong>My account</strong> to update your profile or password; role permissions and scope assignments are maintained by another administrator.
        </div>
      )}

      <Section title="Personal details" description="Update name and contact information.">
        <DetailsForm user={user} editable={abilities.update} />
      </Section>

      <Section
        title="Role assignments"
        description={abilities.administer ? "Changes apply on the user's next request." : undefined}
      >
        <RolesEditor key={user.roles.map((r) => r.name).join()} user={user} editable={abilities.administer} />
      </Section>

      <Section title="Branch & Page scope" description="Determines which orders, customers, and financial reports this staff member can access.">
        <AssignmentsEditor key={[...user.branches.map((b) => b.id), "|", ...user.pages.map((p) => `${p.id}${p.is_owner ? "o" : ""}`)].join()} user={user} editable={abilities.administer} />
      </Section>

      {abilities.view_activity && (
        <Section title="Security & Activity Audit" description="Comprehensive action audit log for this staff account.">
          <ActivityLog userId={user.id} />
        </Section>
      )}
    </div>
  );
}
