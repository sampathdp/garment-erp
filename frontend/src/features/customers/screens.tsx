"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { Modal } from "@/components/ui/modal";
import { PhoneIcon, PlusIcon, SearchIcon, UserIcon, UsersIcon } from "@/components/ui/icons";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { OrdersTableFor } from "@/features/orders/customer-orders";
import { useBranches, usePages } from "@/features/organizations/api";
import { ApiError } from "@/lib/api-client";
import { formatDateTime } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { type Customer, type CustomerFormInput, useCustomer, useCustomers, useDeleteCustomer, useSaveCustomer } from "./api";

function CustomerForm({ customer, onDone }: { customer: Customer | null; onDone: (c: Customer) => void }) {
  const save = useSaveCustomer(customer?.id ?? null);
  const pages = usePages({ per_page: 100, sort: "name" }, customer === null);
  const branches = useBranches({ per_page: 100, sort: "name" });
  const { notify } = useToast();
  const [v, setV] = useState({
    page_id: "",
    branch_id: String(customer?.branch?.id ?? ""),
    name: customer?.name ?? "",
    phone: customer?.phone ?? "",
    whatsapp: customer?.whatsapp ?? "",
    other_phone: customer?.other_phone ?? "",
    address: customer?.address ?? "",
    confirm_distinct: false,
  });
  const apiError = save.error instanceof ApiError ? save.error : null;
  const errors = apiError?.fieldErrors ?? {};

  const submit = () => {
    const input: CustomerFormInput = {
      ...(customer === null ? { page_id: Number(v.page_id) } : {}),
      branch_id: v.branch_id ? Number(v.branch_id) : null,
      name: v.name,
      phone: v.phone,
      whatsapp: v.whatsapp,
      other_phone: v.other_phone || null,
      address: v.address || null,
      ...(v.confirm_distinct ? { confirm_distinct: true } : {}),
    };
    save.mutate(input, { onSuccess: (c) => (notify(customer ? "Customer saved" : `Customer ${c.customer_code} created`, "success"), onDone(c)) });
  };

  return (
    <form noValidate className="flex flex-col gap-4" onSubmit={(e) => (e.preventDefault(), submit())}>
      <FormError message={apiError && (!apiError.isValidation || apiError.code === "possible_duplicate_customer") ? apiError.message : null} />
      <div className="grid gap-4 sm:grid-cols-2">
        {customer === null && (
          <SelectField
            label="Page *"
            options={[{ value: "", label: "Select sales channel" }, ...(pages.data?.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]}
            value={v.page_id}
            error={errors.page_id?.[0]}
            onChange={(e) => setV({ ...v, page_id: e.target.value })}
          />
        )}
        <SelectField
          label="Default Branch"
          options={[{ value: "", label: "None (Global)" }, ...(branches.data?.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))]}
          value={v.branch_id}
          error={errors.branch_id?.[0]}
          onChange={(e) => setV({ ...v, branch_id: e.target.value })}
        />
        <TextField label="Customer name *" placeholder="e.g. Ruwan Silva" maxLength={150} value={v.name} error={errors.name?.[0]} onChange={(e) => setV({ ...v, name: e.target.value })} />
        <TextField label="Phone number *" type="tel" placeholder="077 123 4567" maxLength={20} value={v.phone} error={errors.phone?.[0]} onChange={(e) => setV({ ...v, phone: e.target.value })} />
        <TextField label="WhatsApp number *" type="tel" placeholder="077 123 4567" maxLength={20} value={v.whatsapp} error={errors.whatsapp?.[0]} onChange={(e) => setV({ ...v, whatsapp: e.target.value })} />
        <TextField label="Other number" type="tel" placeholder="Optional backup contact" maxLength={20} value={v.other_phone} error={errors.other_phone?.[0]} onChange={(e) => setV({ ...v, other_phone: e.target.value })} />
      </div>
      <TextAreaField label="Delivery Address" placeholder="Street name, district, postal code" maxLength={200} value={v.address} error={errors.address?.[0]} onChange={(e) => setV({ ...v, address: e.target.value })} />
      {apiError?.code === "possible_duplicate_customer" && (
        <div className="rounded-xl border border-warning/30 bg-warning-soft p-3">
          <Checkbox label="This is a different person who shares the phone number" checked={v.confirm_distinct} onChange={(e) => setV({ ...v, confirm_distinct: e.target.checked })} />
        </div>
      )}
      <div className="flex justify-end gap-2.5 pt-2">
        <Button type="submit" loading={save.isPending}>
          {customer ? "Save changes" : "Create customer"}
        </Button>
      </div>
    </form>
  );
}

export function CustomersScreen() {
  const { can } = useAuth();
  const router = useRouter();
  const [params, setParams] = useState({ page: 1, sort: "name", search: "" });
  const query = useCustomers(useDebounced(params));
  const [creating, setCreating] = useState(false);

  const columns: Column<Customer>[] = [
    {
      key: "code",
      header: "Code",
      sortKey: "customer_code",
      cell: (c) => <span className="font-mono text-xs font-semibold text-muted bg-subtle px-2 py-0.5 rounded">{c.customer_code}</span>,
    },
    {
      key: "name",
      header: "Customer Name",
      sortKey: "name",
      cell: (c) => (
        <Link className="font-semibold text-brand hover:underline" href={`/customers/view/?id=${c.id}`}>
          {c.name}
        </Link>
      ),
    },
    {
      key: "phone",
      header: "Phone",
      cell: (c) => <span className="text-xs font-medium text-ink">{c.phone}</span>,
    },
    {
      key: "whatsapp",
      header: "WhatsApp",
      cell: (c) =>
        c.whatsapp ? (
          <span className="text-xs font-medium text-ink">{c.whatsapp}</span>
        ) : (
          <span className="text-xs text-muted">—</span>
        ),
    },
    {
      key: "page",
      header: "Sales Channel",
      cell: (c) => <span className="text-xs text-muted">{c.page?.name ?? "—"}</span>,
    },
    {
      key: "orders",
      header: "Total Orders",
      cell: (c) => (
        <Badge tone={c.orders_count ? "brand" : "neutral"}>
          {c.orders_count ?? 0} {c.orders_count === 1 ? "job" : "jobs"}
        </Badge>
      ),
    },
  ];

  return (
    <RequirePermission anyOf={["customers.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Customer Directory"
          description="Client directory across your sales pages. Search by name, phone or customer code."
          actions={
            can("customers.manage") ? (
              <Button icon={<PlusIcon size={16} />} onClick={() => setCreating(true)}>
                New customer
              </Button>
            ) : undefined
          }
        />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center">
          <div className="w-full sm:max-w-md">
            <TextField
              label="Search customers"
              placeholder="Search by name, phone or customer code…"
              icon={<SearchIcon size={16} />}
              maxLength={100}
              value={params.search}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
            />
          </div>
        </div>
        <DataTable
          caption="Customers"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(c) => c.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No customers registered yet"
          emptyDescription="Register customers during order intake or add them directly using the button above."
        />
        <Modal open={creating} title="Create New Customer" onClose={() => setCreating(false)}>
          {creating && <CustomerForm customer={null} onDone={(c) => router.push(`/customers/view/?id=${c.id}`)} />}
        </Modal>
      </div>
    </RequirePermission>
  );
}

function CustomerDetail({ id }: { id: number }) {
  const query = useCustomer(id);
  const remove = useDeleteCustomer(id);
  const router = useRouter();
  const { notify } = useToast();
  const [editing, setEditing] = useState(false);
  const [deleting, setDeleting] = useState(false);

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const c = query.data;

  const initials = c.name
    .split(" ")
    .map((n) => n[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={c.name}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/customers/" className="hover:text-ink">Customers</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{c.customer_code}</span>
          </div>
        }
        description={`Registered client · ${c.page?.name ?? "No page"} · Member since ${formatDateTime(c.created_at)}`}
        actions={
          <div className="flex items-center gap-2.5">
            {c.abilities?.update && (
              <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>
                Edit profile
              </Button>
            )}
            {c.abilities?.delete && (c.orders_count ?? 0) === 0 && (
              <Button variant="danger" size="sm" onClick={() => setDeleting(true)}>
                Delete customer
              </Button>
            )}
          </div>
        }
      />

      {/* Hero Contact Card */}
      <div className="rounded-2xl border border-line bg-surface p-6 shadow-xs">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-4">
            <div className="flex size-14 items-center justify-center rounded-2xl bg-brand/10 text-lg font-bold text-brand">
              {initials}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-xl font-bold text-ink">{c.name}</h2>
                <span className="font-mono text-xs font-semibold text-muted bg-subtle px-2 py-0.5 rounded">
                  {c.customer_code}
                </span>
              </div>
              <p className="mt-1 text-xs text-muted">Sales Channel: {c.page?.name ?? "Default"} · Primary Branch: {c.branch?.name ?? "All branches"}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge tone="brand">
              {c.orders_count ?? 0} Total {c.orders_count === 1 ? "Order" : "Orders"}
            </Badge>
          </div>
        </div>

        <div className="mt-6 grid gap-4 border-t border-line/60 pt-4 sm:grid-cols-4">
          <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
            <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Primary Phone</span>
            <span className="text-sm font-semibold text-ink">{c.phone}</span>
          </div>
          <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
            <span className="text-[11px] font-bold uppercase tracking-wider text-muted">WhatsApp</span>
            <span className="text-sm font-semibold text-ink">{c.whatsapp ?? "—"}</span>
          </div>
          <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
            <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Secondary Phone</span>
            <span className="text-sm font-semibold text-ink">{c.other_phone ?? "—"}</span>
          </div>
          <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
            <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Address</span>
            <span className="text-sm font-semibold text-ink">{c.address ?? "—"}</span>
          </div>
        </div>
      </div>

      {/* Orders History Section */}
      <Section title="Order History" description="All production jobs associated with this customer profile.">
        <OrdersTableFor customerId={c.id} />
      </Section>

      <Modal open={editing} title="Edit Customer Profile" onClose={() => setEditing(false)}>
        {editing && <CustomerForm customer={c} onDone={() => setEditing(false)} />}
      </Modal>

      <ConfirmDialog
        open={deleting}
        tone="danger"
        title="Delete customer profile?"
        description="This action cannot be undone. Customers with active or historical orders cannot be deleted."
        confirmLabel="Confirm Delete"
        loading={remove.isPending}
        onCancel={() => setDeleting(false)}
        onConfirm={() => remove.mutate(undefined, { onSuccess: () => (notify("Customer deleted", "success"), router.push("/customers/")) })}
      />
    </div>
  );
}

export function CustomerScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Customer not found" /> : <CustomerDetail id={id} />)}</WithIdParam>;
}
