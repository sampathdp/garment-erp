"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { FormError, PageHeader, Section, StatusBadge } from "@/components/ui/form-controls";
import { PlusIcon } from "@/components/ui/icons";
import { RequirePermission } from "@/components/ui/record-page";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { ApiError } from "@/lib/api-client";
import { useMaterials } from "@/features/setup/api";
import { type CatalogItem, useOrderTypes, useProductCategories, useSaveCatalogItem } from "./api";

function CatalogList({
  title,
  description,
  kind,
  items,
  isPending,
  error,
}: {
  title: string;
  description: string;
  kind: "order-types" | "product-categories" | "materials";
  items: CatalogItem[] | undefined;
  isPending: boolean;
  error: unknown;
}) {
  const { can } = useAuth();
  const save = useSaveCatalogItem(kind);
  const { notify } = useToast();
  const [draft, setDraft] = useState({ code: "", name: "" });
  const canManage = can("pricing.manage");
  const fieldErrors = save.error instanceof ApiError ? save.error.fieldErrors : {};

  return (
    <Section title={title} description={description}>
      {isPending ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} />
      ) : (
        <ul className="mb-5 divide-y divide-line/60 rounded-xl border border-line bg-surface text-xs shadow-xs">
          {(items ?? []).map((item) => (
            <li key={item.id} className="flex items-center justify-between gap-3 p-3">
              <div className="flex items-center gap-2">
                <span className="font-semibold text-sm text-ink">{item.name}</span>
                <span className="font-mono text-xs text-muted bg-subtle px-1.5 py-0.5 rounded">{item.code}</span>
              </div>
              <div className="flex items-center gap-2">
                <StatusBadge active={item.is_active} />
                {canManage && (
                  <Button variant="ghost" size="sm" onClick={() => save.mutate({ id: item.id, is_active: !item.is_active })}>
                    {item.is_active ? "Deactivate" : "Activate"}
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {canManage && (
        <form
          noValidate
          className="rounded-xl border border-line bg-subtle/30 p-4"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate(draft, { onSuccess: () => (notify(`${draft.name} added`, "success"), setDraft({ code: "", name: "" })) });
          }}
        >
          <FormError message={save.error instanceof ApiError && !save.error.isValidation ? save.error.message : null} />
          <div className="grid gap-3 sm:grid-cols-3">
            <TextField label="Identifier Code *" maxLength={30} value={draft.code} placeholder="e.g. polo_tshirt" hint="Immutable system code" error={fieldErrors.code?.[0]} onChange={(e) => setDraft({ ...draft, code: e.target.value.toLowerCase() })} />
            <TextField label="Display Name *" maxLength={80} value={draft.name} placeholder="e.g. Polo T-Shirt" error={fieldErrors.name?.[0]} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
            <div className="flex items-end">
              <Button type="submit" size="md" icon={<PlusIcon size={14} />} loading={save.isPending}>
                Add entry
              </Button>
            </div>
          </div>
        </form>
      )}
    </Section>
  );
}

export function CatalogScreen() {
  const types = useOrderTypes(false);
  const categories = useProductCategories(false);
  const materials = useMaterials(false);
  return (
    <RequirePermission anyOf={["pricing.view", "pricing.manage", "orders.create"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Order types and categories" description="Catalog options offered during new order intake. Entries are permanent and deactivated when discontinued." />
        <CatalogList title="Order types" description="Intake order types (e.g. Single Order, Bulk Order)." kind="order-types" items={types.data} isPending={types.isPending} error={types.error} />
        <CatalogList title="Product / item categories" description="Garment apparel categories (Jersey, Arm Cut, Short, Bottom, T-Shirt)." kind="product-categories" items={categories.data} isPending={categories.isPending} error={categories.error} />
        <CatalogList title="Fabric types" description="Available fabric materials for garment manufacturing. Pricing matrices can depend on fabric selection." kind="materials" items={materials.data} isPending={materials.isPending} error={materials.error} />
      </div>
    </RequirePermission>
  );
}
