"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, StatusBadge, TextAreaField } from "@/components/ui/form-controls";
import { GlobeIcon, PlusIcon, SearchIcon, UserIcon } from "@/components/ui/icons";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { useUsers } from "@/features/users/api";
import { ApiError } from "@/lib/api-client";
import { formatMoney, minorToInput, parseMoneyToMinor } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { PAGE_PLATFORMS, type Page, type PageInput, type PageMember, usePage, usePageMembers, usePages, useSavePage, useSavePageMembers } from "./api";

const linkButton = "inline-flex h-9 items-center gap-1.5 rounded-xl bg-brand px-4 text-xs font-semibold text-white shadow-xs hover:bg-brand-strong transition active:scale-[0.98]";

export function PagesScreen() {
  const { can, user } = useAuth();
  const [params, setParams] = useState({ page: 1, sort: "name", search: "" });
  const query = usePages(useDebounced(params));

  const columns: Column<Page>[] = [
    { key: "code", header: "Code", sortKey: "code", cell: (p) => <span className="font-mono text-xs font-bold text-muted bg-subtle px-2 py-0.5 rounded">{p.code}</span> },
    {
      key: "name",
      header: "Channel Name",
      sortKey: "name",
      cell: (p) => (
        <div className="flex items-center gap-2">
          <GlobeIcon size={16} className="text-muted shrink-0" />
          <Link href={`/settings/pages/view/?id=${p.id}`} className="font-semibold text-brand hover:underline">
            {p.name}
          </Link>
        </div>
      ),
    },
    { key: "platform", header: "Platform", cell: (p) => <span className="text-xs font-medium capitalize text-muted">{PAGE_PLATFORMS.find((x) => x.value === p.platform)?.label ?? p.platform}</span> },
    { key: "owners", header: "Page Owners", cell: (p) => <span className="text-xs font-medium text-ink">{p.owners?.map((o) => o.name).join(", ") || "—"}</span> },
    { key: "advance", header: "Default Advance", cell: (p) => <Badge tone="brand">{p.pricing.default_advance_percent}%</Badge> },
    { key: "status", header: "Status", cell: (p) => <StatusBadge active={p.is_active} /> },
  ];

  return (
    <RequirePermission anyOf={["pages.view"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Sales Channels (Pages)"
          description="Facebook pages and digital channels. Each channel configures its pricing defaults, invoice branding, and designated Page Owners."
          actions={
            can("pages.manage") && user?.scope.all_pages ? (
              <Link href="/settings/pages/view/" className={linkButton}>
                <PlusIcon size={16} />
                <span>New page</span>
              </Link>
            ) : undefined
          }
        />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center">
          <div className="w-full sm:max-w-md">
            <TextField
              label="Search channels"
              placeholder="Filter by page name or code…"
              icon={<SearchIcon size={16} />}
              maxLength={100}
              value={params.search}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
            />
          </div>
        </div>
        <DataTable
          caption="Pages"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(p) => p.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No pages found"
          emptyDescription="You only see sales pages assigned to your scope."
        />
      </div>
    </RequirePermission>
  );
}

type PageFormValues = Omit<PageInput, "default_delivery_fee_minor"> & { code: string; invoice_prefix: string; delivery_fee: string };

function PageForm({ page }: { page: Page | null }) {
  const router = useRouter();
  const { notify } = useToast();
  const save = useSavePage(page?.id ?? null);
  const editable = page === null || Boolean(page.abilities?.update);
  const [values, setValues] = useState<PageFormValues>({
    code: page?.code ?? "",
    invoice_prefix: page?.invoice_prefix ?? "",
    name: page?.name ?? "",
    platform: page?.platform ?? "facebook",
    external_url: page?.external_url ?? "",
    contact_email: page?.contact_email ?? "",
    contact_phone: page?.contact_phone ?? "",
    address: page?.address ?? "",
    default_advance_percent: page?.pricing.default_advance_percent ?? 30,
    delivery_fee: minorToInput(page?.pricing.default_delivery_fee_minor ?? 0),
    invoice_header_name: page?.invoice_header_name ?? "",
    invoice_address: page?.invoice_address ?? "",
    invoice_phone: page?.invoice_phone ?? "",
    is_active: page?.is_active ?? true,
  });
  const [feeError, setFeeError] = useState<string | null>(null);
  const errors = save.error instanceof ApiError ? save.error.fieldErrors : {};
  const formError = save.error instanceof ApiError && !save.error.isValidation ? save.error.message : null;
  const set = <K extends keyof PageFormValues>(key: K, value: PageFormValues[K]) => setValues((v) => ({ ...v, [key]: value }));
  const orNull = (s: string | null) => (s === null || s.trim() === "" ? null : s.trim());

  const submit = () => {
    const fee = parseMoneyToMinor(values.delivery_fee);
    if (fee === null) {
      setFeeError("Enter an amount such as 350 or 350.00");
      return;
    }
    setFeeError(null);
    const { code, invoice_prefix, ...withFee } = values;
    const rest: Omit<typeof withFee, "delivery_fee"> = Object.fromEntries(Object.entries(withFee).filter(([k]) => k !== "delivery_fee")) as Omit<typeof withFee, "delivery_fee">;
    const payload: PageInput = {
      ...rest,
      external_url: orNull(rest.external_url),
      contact_email: orNull(rest.contact_email),
      contact_phone: orNull(rest.contact_phone),
      address: orNull(rest.address),
      invoice_address: orNull(rest.invoice_address),
      invoice_phone: orNull(rest.invoice_phone),
      default_delivery_fee_minor: fee,
      ...(page ? {} : { code, invoice_prefix }),
    };
    save.mutate(payload, {
      onSuccess: (saved) => {
        notify(page ? "Page saved" : "Page created", "success");
        if (!page) router.replace(`/settings/pages/view/?id=${saved.id}`);
      },
    });
  };

  return (
    <form
      noValidate
      className="flex flex-col gap-6"
      onSubmit={(e) => {
        e.preventDefault();
        submit();
      }}
    >
      <FormError message={formError} />
      <fieldset disabled={!editable} className="flex flex-col gap-6">
        <Section title="Channel Identity" description="Sales channel identifier and platform linkage.">
          <div className="grid gap-4 md:grid-cols-3">
            <TextField label="Code *" value={values.code} maxLength={20} disabled={page !== null} hint={page ? "Codes never change." : "For example JRSY"} error={errors.code?.[0]} onChange={(e) => set("code", e.target.value.toUpperCase())} />
            <TextField label="Channel Name *" placeholder="e.g. Jersey World Online" value={values.name} maxLength={120} error={errors.name?.[0]} onChange={(e) => set("name", e.target.value)} />
            <SelectField label="Platform" value={values.platform} options={PAGE_PLATFORMS} error={errors.platform?.[0]} onChange={(e) => set("platform", e.target.value)} />
            <div className="md:col-span-3">
              <TextField label="Social Media Link" type="url" placeholder="https://facebook.com/..." value={values.external_url ?? ""} maxLength={255} error={errors.external_url?.[0]} onChange={(e) => set("external_url", e.target.value)} />
            </div>
          </div>
        </Section>
        <Section title="Contact Information">
          <div className="grid gap-4 md:grid-cols-2">
            <TextField label="Contact Email" type="email" placeholder="sales@brand.lk" value={values.contact_email ?? ""} maxLength={191} error={errors.contact_email?.[0]} onChange={(e) => set("contact_email", e.target.value)} />
            <TextField label="Contact Phone" type="tel" placeholder="077 123 4567" value={values.contact_phone ?? ""} maxLength={20} error={errors.contact_phone?.[0]} onChange={(e) => set("contact_phone", e.target.value)} />
            <div className="md:col-span-2">
              <TextAreaField label="Public Address" placeholder="Address displayed on social pages" value={values.address ?? ""} maxLength={500} error={errors.address?.[0]} onChange={(e) => set("address", e.target.value)} />
            </div>
          </div>
        </Section>
        <Section title="Pricing Configuration" description="Default financial defaults for new orders received through this channel.">
          <div className="grid gap-4 md:grid-cols-3">
            <TextField label="Currency" value="LKR" disabled readOnly />
            <TextField
              label="Default advance (%)"
              type="number"
              min={30}
              max={100}
              value={values.default_advance_percent}
              hint="At least 30% of total including delivery and tax."
              error={errors.default_advance_percent?.[0]}
              onChange={(e) => set("default_advance_percent", Number(e.target.value))}
            />
            <TextField label="Default delivery fee (LKR)" inputMode="decimal" value={values.delivery_fee} maxLength={16} error={feeError ?? errors.default_delivery_fee_minor?.[0]} onChange={(e) => set("delivery_fee", e.target.value)} />
          </div>
        </Section>
        <Section title="Invoice & Branding Profile" description="Legal company header and prefix printed on generated PDF snapshots.">
          <div className="grid gap-4 md:grid-cols-3">
            <TextField label="Invoice Prefix *" value={values.invoice_prefix} maxLength={10} disabled={page !== null} hint={page ? "Prefixes never change." : "e.g. JW-"} error={errors.invoice_prefix?.[0]} onChange={(e) => set("invoice_prefix", e.target.value.toUpperCase())} />
            <TextField label="Header Name on Invoice *" value={values.invoice_header_name} maxLength={150} error={errors.invoice_header_name?.[0]} onChange={(e) => set("invoice_header_name", e.target.value)} />
            <TextField label="Invoice Phone" type="tel" value={values.invoice_phone ?? ""} maxLength={20} error={errors.invoice_phone?.[0]} onChange={(e) => set("invoice_phone", e.target.value)} />
            <div className="md:col-span-3">
              <TextAreaField label="Invoice Address & Registration" value={values.invoice_address ?? ""} maxLength={500} error={errors.invoice_address?.[0]} onChange={(e) => set("invoice_address", e.target.value)} />
            </div>
          </div>
        </Section>
        <div className="rounded-xl border border-line bg-subtle/40 p-3">
          <Checkbox label="Active Channel" description="Inactive pages remain in audit logs but cannot receive new customer orders." checked={values.is_active} onChange={(e) => set("is_active", e.target.checked)} />
        </div>
      </fieldset>
      {editable && (
        <div className="flex justify-end pt-2">
          <Button type="submit" loading={save.isPending}>
            {page ? "Save page settings" : "Create sales page"}
          </Button>
        </div>
      )}
    </form>
  );
}

function MembersEditor({ page }: { page: Page }) {
  const { can } = useAuth();
  const { notify } = useToast();
  const members = usePageMembers(page.id, can("users.view"));
  const save = useSavePageMembers(page.id);
  const [draft, setDraft] = useState<PageMember[] | null>(null);
  const [search, setSearch] = useState("");
  const candidates = useUsers({ page: 1, sort: "name", search: useDebounced(search), is_active: "1", role: "" });
  const editable = Boolean(page.abilities?.manage_members);

  if (!can("users.view")) return <p className="text-xs text-muted">Owners: {page.owners?.map((o) => o.name).join(", ") || "None assigned"}</p>;
  if (members.error) return <ErrorState error={members.error} onRetry={() => members.refetch()} />;
  if (members.isPending) return <LoadingState />;

  const list = draft ?? members.data;
  const update = (next: PageMember[]) => setDraft(next);

  return (
    <div className="flex flex-col gap-4">
      {list.length === 0 ? (
        <EmptyState title="No members assigned to this channel" />
      ) : (
        <ul className="divide-y divide-line/60 rounded-xl border border-line bg-surface shadow-xs">
          {list.map((m) => (
            <li key={m.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-xs">
              <div className="flex items-center gap-2.5">
                <div className="flex size-7 items-center justify-center rounded-full bg-brand/10 text-[11px] font-bold text-brand">
                  {m.name.slice(0, 1).toUpperCase()}
                </div>
                <div>
                  <span className="font-semibold text-ink">{m.name}</span>
                  <span className="ml-1.5 text-muted">({m.email})</span>
                  <div className="mt-0.5 flex flex-wrap gap-1">
                    {m.roles.map((r) => (
                      <Badge key={r.name} tone="neutral" className="text-[10px]">{r.label}</Badge>
                    ))}
                    {!m.is_active && <Badge tone="danger" className="text-[10px]">Inactive</Badge>}
                  </div>
                </div>
              </div>
              <div className="flex items-center gap-4">
                <Checkbox label="Page Owner" checked={m.is_owner} disabled={!editable} onChange={(e) => update(list.map((x) => (x.id === m.id ? { ...x, is_owner: e.target.checked } : x)))} />
                {editable && (
                  <Button variant="ghost" size="sm" className="text-danger hover:bg-danger-soft" onClick={() => update(list.filter((x) => x.id !== m.id))}>
                    Remove
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {editable && (
        <div className="mt-2 flex flex-col gap-3">
          <div className="sm:max-w-md">
            <TextField label="Add staff member to channel" placeholder="Type user name or email to add…" icon={<SearchIcon size={14} />} maxLength={100} value={search} onChange={(e) => setSearch(e.target.value)} />
            {search.trim() !== "" && (
              <div className="mt-1 divide-y divide-line/60 rounded-xl border border-line bg-surface shadow-md">
                {candidates.data?.data
                  .filter((u) => !list.some((m) => m.id === u.id))
                  .slice(0, 5)
                  .map((u) => (
                    <button
                      key={u.id}
                      type="button"
                      className="flex w-full items-center justify-between p-2.5 text-left text-xs hover:bg-subtle transition cursor-pointer"
                      onClick={() => {
                        update([...list, { id: u.id, name: u.name, email: u.email, is_active: u.is_active, is_owner: false, roles: u.roles }]);
                        setSearch("");
                      }}
                    >
                      <span className="font-semibold text-ink">{u.name}</span>
                      <span className="text-muted">{u.email}</span>
                    </button>
                  ))}
              </div>
            )}
          </div>
          {save.error && <FormError message={save.error instanceof ApiError ? (Object.values(save.error.fieldErrors)[0]?.[0] ?? save.error.message) : "Could not save members."} />}
          <div className="flex gap-2 pt-2">
            <Button
              disabled={draft === null}
              loading={save.isPending}
              onClick={() =>
                save.mutate(list.map((m) => ({ user_id: m.id, is_owner: m.is_owner })), {
                  onSuccess: () => {
                    setDraft(null);
                    notify("Members saved", "success");
                  },
                })
              }
            >
              Save channel members
            </Button>
            {draft !== null && (
              <Button variant="secondary" onClick={() => setDraft(null)}>
                Discard changes
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function ExistingPage({ id }: { id: number }) {
  const page = usePage(id);
  if (page.error) return <ErrorState error={page.error} onRetry={() => page.refetch()} />;
  if (page.isPending) return <LoadingState />;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={page.data.name}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/settings/pages/" className="hover:text-ink">Pages</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{page.data.name}</span>
          </div>
        }
        description={
          <span className="flex flex-wrap items-center gap-2">
            <StatusBadge active={page.data.is_active} /> {page.data.members_count ?? 0} members · Default delivery charge: {formatMoney(page.data.pricing.default_delivery_fee_minor)}
          </span>
        }
      />
      <PageForm key={`${page.data.id}-${page.data.name}-${page.data.is_active}`} page={page.data} />
      <Section title="Channel Owners & Staff Members" description="Assigned staff have visibility of this channel. Designated Page Owners are responsible for order sign-offs.">
        <MembersEditor page={page.data} />
      </Section>
    </div>
  );
}

export function PageScreen() {
  const { can } = useAuth();
  return (
    <RequirePermission anyOf={["pages.view"]}>
      <WithIdParam>
        {(id) =>
          id !== null ? (
            <ExistingPage id={id} />
          ) : can("pages.manage") ? (
            <div className="flex flex-col gap-6">
              <PageHeader
                title="New sales channel"
                breadcrumb={
                  <div className="flex items-center gap-1.5 text-xs text-muted">
                    <Link href="/settings/pages/" className="hover:text-ink">Pages</Link>
                    <span>/</span>
                    <span className="font-semibold text-ink">New Channel</span>
                  </div>
                }
                description="Configure a new Facebook page or online sales brand."
              />
              <PageForm page={null} />
            </div>
          ) : (
            <EmptyState title="Page not found" />
          )
        }
      </WithIdParam>
    </RequirePermission>
  );
}
