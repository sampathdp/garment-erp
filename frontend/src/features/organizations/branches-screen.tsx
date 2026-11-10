"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Checkbox, FormError, PageHeader, StatusBadge, TextAreaField } from "@/components/ui/form-controls";
import { BuildingIcon, PlusIcon, SearchIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission } from "@/components/ui/record-page";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { ApiError } from "@/lib/api-client";
import { formatDateTime } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { type Branch, type BranchInput, useBranches, useSaveBranch } from "./api";

function BranchForm({ branch, onDone }: { branch: Branch | null; onDone: () => void }) {
  const save = useSaveBranch(branch?.id ?? null);
  const { notify } = useToast();
  const [values, setValues] = useState<BranchInput & { code: string }>({
    code: branch?.code ?? "",
    name: branch?.name ?? "",
    address: branch?.address ?? "",
    phone: branch?.phone ?? "",
    is_active: branch?.is_active ?? true,
  });
  const errors = save.error instanceof ApiError ? save.error.fieldErrors : {};
  const formError = save.error instanceof ApiError && !save.error.isValidation ? save.error.message : null;

  const submit = () => {
    const { code, ...rest } = values;
    const payload: BranchInput = { ...rest, address: rest.address || null, phone: rest.phone || null, ...(branch ? {} : { code }) };
    save.mutate(payload, {
      onSuccess: () => {
        notify(branch ? "Branch saved" : "Branch created", "success");
        onDone();
      },
    });
  };

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        submit();
      }}
    >
      <FormError message={formError} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label="Code *" value={values.code} maxLength={20} disabled={branch !== null} hint={branch ? "Codes never change." : "For example CMB"} error={errors.code?.[0]} onChange={(e) => setValues({ ...values, code: e.target.value.toUpperCase() })} />
        <TextField label="Name *" placeholder="e.g. Colombo Central Branch" value={values.name} maxLength={120} error={errors.name?.[0]} onChange={(e) => setValues({ ...values, name: e.target.value })} />
        <TextField label="Phone" type="tel" placeholder="011 234 5678" value={values.phone ?? ""} maxLength={20} error={errors.phone?.[0]} onChange={(e) => setValues({ ...values, phone: e.target.value })} />
      </div>
      <TextAreaField label="Physical Address" placeholder="Address of factory or shop location" value={values.address ?? ""} maxLength={500} error={errors.address?.[0]} onChange={(e) => setValues({ ...values, address: e.target.value })} />
      <div className="rounded-xl border border-line bg-subtle/40 p-3">
        <Checkbox label="Active Branch" description="Inactive branches remain in audit history but cannot be selected for new customer orders." checked={values.is_active} onChange={(e) => setValues({ ...values, is_active: e.target.checked })} />
      </div>
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" loading={save.isPending}>
          {branch ? "Save changes" : "Create branch"}
        </Button>
      </div>
    </form>
  );
}

export function BranchesScreen() {
  const { can, user } = useAuth();
  const [params, setParams] = useState({ page: 1, sort: "name", search: "" });
  const query = useBranches(useDebounced(params));
  const [editing, setEditing] = useState<Branch | null | undefined>(undefined);
  const canManage = can("branches.manage");

  const columns: Column<Branch>[] = [
    { key: "code", header: "Code", sortKey: "code", cell: (b) => <span className="font-mono text-xs font-bold text-muted bg-subtle px-2 py-0.5 rounded">{b.code}</span> },
    {
      key: "name",
      header: "Branch Name",
      sortKey: "name",
      cell: (b) => (
        <div className="flex items-center gap-2">
          <BuildingIcon size={16} className="text-muted shrink-0" />
          <span className="font-semibold text-ink">{b.name}</span>
        </div>
      ),
    },
    { key: "phone", header: "Phone", cell: (b) => <span className="text-xs text-muted">{b.phone ?? "—"}</span> },
    { key: "users", header: "Staff Members", cell: (b) => <span className="text-xs font-medium text-ink">{b.members_count ?? "—"}</span> },
    { key: "status", header: "Status", cell: (b) => <StatusBadge active={b.is_active} /> },
    { key: "created", header: "Registered", sortKey: "created_at", cell: (b) => <span className="text-xs text-muted">{formatDateTime(b.created_at)}</span> },
    ...(canManage
      ? [
          {
            key: "actions",
            header: "",
            className: "text-right",
            cell: (b: Branch) => (
              <Button variant="ghost" size="sm" onClick={() => setEditing(b)}>
                Edit
              </Button>
            ),
          },
        ]
      : []),
  ];

  return (
    <RequirePermission anyOf={["branches.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Branches"
          description="Physical branch outlets and manufacturing sites. Branches are permanent and never deleted, only deactivated when closed."
          actions={
            canManage && user?.scope.all_branches ? (
              <Button icon={<PlusIcon size={16} />} onClick={() => setEditing(null)}>
                New branch
              </Button>
            ) : undefined
          }
        />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center">
          <div className="w-full sm:max-w-md">
            <TextField
              label="Search branches"
              placeholder="Filter by branch name or code…"
              icon={<SearchIcon size={16} />}
              maxLength={100}
              value={params.search}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
            />
          </div>
        </div>
        <DataTable
          caption="Branches"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(b) => b.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No branches found"
        />
        <Modal open={editing !== undefined} title={editing ? `Edit ${editing.name}` : "Create New Branch"} onClose={() => setEditing(undefined)}>
          {editing !== undefined && <BranchForm branch={editing} onDone={() => setEditing(undefined)} />}
        </Modal>
      </div>
    </RequirePermission>
  );
}
