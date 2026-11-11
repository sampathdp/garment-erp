"use client";

import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import {
  Badge,
  Checkbox,
  FormError,
  PageHeader,
  SelectField,
  StatusBadge,
} from "@/components/ui/form-controls";
import {
  CheckCircleIcon,
  ChevronRightIcon,
  LayersIcon,
  LockIcon,
  PencilIcon,
  PlusIcon,
  RefreshCwIcon,
  ScissorsIcon,
  SearchIcon,
  ShirtIcon,
  SparklesIcon,
  TagIcon,
  XIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { useMaterials } from "@/features/setup/api";
import { ApiError } from "@/lib/api-client";
import {
  type CatalogItem,
  useOrderTypes,
  useProductCategories,
  useSaveCatalogItem,
} from "./api";

type CatalogKind = "product-categories" | "order-types" | "materials";

interface KindConfig {
  kind: CatalogKind;
  singular: string;
  plural: string;
  description: string;
  usageHint: string;
  codePlaceholder: string;
  namePlaceholder: string;
  icon: typeof ShirtIcon;
  colorClass: string;
}

const KIND_CONFIGS: Record<CatalogKind, KindConfig> = {
  "product-categories": {
    kind: "product-categories",
    singular: "Garment Category",
    plural: "Garment Categories",
    description:
      "Apparel models and garment styles (Jersey, Arm Cut, Short, T-Shirt, etc.) used for order intake, pattern creation, and size chart assignment.",
    usageHint: "Referenced during order intake & mapped to size charts in Garment Setup",
    codePlaceholder: "e.g. polo_tshirt",
    namePlaceholder: "e.g. Polo T-Shirt",
    icon: ShirtIcon,
    colorClass: "bg-brand/10 text-brand border-brand/20",
  },
  "order-types": {
    kind: "order-types",
    singular: "Order Type",
    plural: "Order Types",
    description:
      "Classification of customer orders (Single, Bulk, Custom, Sublimation) dictating intake workflow routing and dual sign-off rules.",
    usageHint: "Selected at initial order intake to determine single vs bulk processing rules",
    codePlaceholder: "e.g. corporate_bulk",
    namePlaceholder: "e.g. Corporate Bulk Order",
    icon: LayersIcon,
    colorClass: "bg-amber-500/10 text-amber-500 border-amber-500/20",
  },
  materials: {
    kind: "materials",
    singular: "Fabric Material",
    plural: "Fabric Materials",
    description:
      "Textile fabrics and materials (Micro-Poly, Dry-Fit, Cotton, etc.) available for garment manufacturing and pricing calculations.",
    usageHint: "Selected during job details submission & used in cutting/printing gate passes",
    codePlaceholder: "e.g. dryfit_mesh",
    namePlaceholder: "e.g. Dry-Fit Mesh",
    icon: ScissorsIcon,
    colorClass: "bg-emerald-500/10 text-emerald-500 border-emerald-500/20",
  },
};

function slugify(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 30);
}

function StatCard({
  label,
  value,
  subtext,
  icon: Icon,
  color,
}: {
  label: string;
  value: number | string;
  subtext: string;
  icon: typeof ShirtIcon;
  color: string;
}) {
  return (
    <div className="flex items-center gap-4 rounded-2xl border border-line bg-surface p-4 shadow-xs transition hover:border-brand/40">
      <div className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${color}`}>
        <Icon size={22} />
      </div>
      <div className="flex flex-col min-w-0">
        <span className="text-[11px] font-bold uppercase tracking-wider text-muted truncate">
          {label}
        </span>
        <span className="text-xl font-extrabold text-ink tracking-tight">{value}</span>
        <span className="text-xs text-muted truncate">{subtext}</span>
      </div>
    </div>
  );
}

export function CatalogScreen() {
  const { can } = useAuth();
  const { notify } = useToast();
  const canManage = can("pricing.manage");

  // Queries
  const categoriesQuery = useProductCategories(false);
  const typesQuery = useOrderTypes(false);
  const materialsQuery = useMaterials(false);

  // Mutations
  const saveCategory = useSaveCatalogItem("product-categories");
  const saveType = useSaveCatalogItem("order-types");
  const saveMaterial = useSaveCatalogItem("materials");

  // State
  const [activeTab, setActiveTab] = useState<CatalogKind>("product-categories");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | "active" | "inactive">("all");
  const [viewMode, setViewMode] = useState<"grid" | "table">("grid");

  // Modal states
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [createKind, setCreateKind] = useState<CatalogKind>("product-categories");
  const [createForm, setCreateForm] = useState({
    name: "",
    code: "",
    codeEdited: false,
    is_active: true,
  });

  const [editItem, setEditItem] = useState<{ kind: CatalogKind; item: CatalogItem } | null>(null);
  const [editForm, setEditForm] = useState({ name: "", is_active: true });

  const isPending =
    categoriesQuery.isPending || typesQuery.isPending || materialsQuery.isPending;
  const error = categoriesQuery.error || typesQuery.error || materialsQuery.error;

  const categories = categoriesQuery.data ?? [];
  const types = typesQuery.data ?? [];
  const materials = materialsQuery.data ?? [];

  // Active counts
  const catActive = categories.filter((c) => c.is_active).length;
  const typesActive = types.filter((t) => t.is_active).length;
  const matActive = materials.filter((m) => m.is_active).length;

  const totalItems = categories.length + types.length + materials.length;
  const totalActive = catActive + typesActive + matActive;
  const healthPercent = totalItems > 0 ? Math.round((totalActive / totalItems) * 100) : 100;

  // Active items per current tab
  const currentItems = useMemo(() => {
    switch (activeTab) {
      case "product-categories":
        return categories;
      case "order-types":
        return types;
      case "materials":
        return materials;
      default:
        return [];
    }
  }, [activeTab, categories, types, materials]);

  // Filtered items based on search and status
  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    return currentItems.filter((item) => {
      const matchesSearch =
        q === "" ||
        item.name.toLowerCase().includes(q) ||
        item.code.toLowerCase().includes(q);
      const matchesStatus =
        statusFilter === "all" ||
        (statusFilter === "active" && item.is_active) ||
        (statusFilter === "inactive" && !item.is_active);
      return matchesSearch && matchesStatus;
    });
  }, [currentItems, search, statusFilter]);

  const activeKindConfig = KIND_CONFIGS[activeTab];

  // Helper for mutation per kind
  const getSaveMutation = (kind: CatalogKind) => {
    switch (kind) {
      case "product-categories":
        return saveCategory;
      case "order-types":
        return saveType;
      case "materials":
        return saveMaterial;
    }
  };

  const handleToggleActive = (kind: CatalogKind, item: CatalogItem) => {
    const mutation = getSaveMutation(kind);
    const newStatus = !item.is_active;
    mutation.mutate(
      { id: item.id, is_active: newStatus },
      {
        onSuccess: () => {
          notify(
            `${item.name} ${newStatus ? "activated" : "deactivated"} successfully`,
            "success",
          );
        },
      },
    );
  };

  const openCreateModal = (presetKind?: CatalogKind) => {
    const k = presetKind ?? activeTab;
    setCreateKind(k);
    setCreateForm({ name: "", code: "", codeEdited: false, is_active: true });
    setIsCreateOpen(true);
  };

  const handleCreateSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!createForm.name.trim() || !createForm.code.trim()) return;

    const mutation = getSaveMutation(createKind);
    mutation.mutate(
      {
        code: createForm.code.trim(),
        name: createForm.name.trim(),
        is_active: createForm.is_active,
      },
      {
        onSuccess: (res) => {
          notify(`${res.name} added to ${KIND_CONFIGS[createKind].plural}`, "success");
          setIsCreateOpen(false);
          setActiveTab(createKind);
        },
      },
    );
  };

  const openEditModal = (kind: CatalogKind, item: CatalogItem) => {
    setEditItem({ kind, item });
    setEditForm({ name: item.name, is_active: item.is_active });
  };

  const handleEditSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editItem || !editForm.name.trim()) return;

    const mutation = getSaveMutation(editItem.kind);
    mutation.mutate(
      {
        id: editItem.item.id,
        name: editForm.name.trim(),
        is_active: editForm.is_active,
      },
      {
        onSuccess: (res) => {
          notify(`${res.name} updated successfully`, "success");
          setEditItem(null);
        },
      },
    );
  };

  if (isPending) return <LoadingState label="Loading catalog taxonomy…" />;
  if (error) {
    return (
      <ErrorState
        error={error}
        onRetry={() => {
          void categoriesQuery.refetch();
          void typesQuery.refetch();
          void materialsQuery.refetch();
        }}
      />
    );
  }

  const currentMutation = getSaveMutation(activeTab);
  const createMutation = getSaveMutation(createKind);
  const createApiError = createMutation.error instanceof ApiError ? createMutation.error : null;
  const createFieldErrors = createApiError?.fieldErrors ?? {};

  const editMutation = editItem ? getSaveMutation(editItem.kind) : null;
  const editApiError = editMutation?.error instanceof ApiError ? editMutation.error : null;
  const editFieldErrors = editApiError?.fieldErrors ?? {};

  return (
    <RequirePermission anyOf={["pricing.view", "pricing.manage", "orders.create"]}>
      <div className="flex flex-col gap-6">
        {/* Page Header */}
        <PageHeader
          title="Catalog & Taxonomy Settings"
          description="Configure core garment categories, intake order types, and fabric materials used across intake pricing matrices and manufacturing."
          actions={
            canManage ? (
              <Button
                icon={<PlusIcon size={16} />}
                onClick={() => openCreateModal()}
                className="shadow-xs"
              >
                Add new entry
              </Button>
            ) : undefined
          }
        />

        {/* Executive Overview Ribbon */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label="Garment Categories"
            value={categories.length}
            subtext={`${catActive} active categories`}
            icon={ShirtIcon}
            color="bg-brand/10 text-brand"
          />
          <StatCard
            label="Order Types"
            value={types.length}
            subtext={`${typesActive} active types`}
            icon={LayersIcon}
            color="bg-amber-500/10 text-amber-500"
          />
          <StatCard
            label="Fabric Materials"
            value={materials.length}
            subtext={`${matActive} active materials`}
            icon={ScissorsIcon}
            color="bg-emerald-500/10 text-emerald-500"
          />
          <StatCard
            label="Active Utilization"
            value={`${healthPercent}%`}
            subtext={`${totalActive} of ${totalItems} items enabled`}
            icon={CheckCircleIcon}
            color="bg-purple-500/10 text-purple-500"
          />
        </div>

        {/* Navigation Tabs */}
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-px">
            <div className="flex gap-2">
              {(
                [
                  { key: "product-categories", label: "Garment Categories", count: categories.length, icon: ShirtIcon },
                  { key: "order-types", label: "Order Types", count: types.length, icon: LayersIcon },
                  { key: "materials", label: "Fabric Materials", count: materials.length, icon: ScissorsIcon },
                ] as const
              ).map((tab) => {
                const Icon = tab.icon;
                const isActive = activeTab === tab.key;
                return (
                  <button
                    key={tab.key}
                    type="button"
                    onClick={() => {
                      setActiveTab(tab.key);
                      setSearch("");
                    }}
                    className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition cursor-pointer ${
                      isActive
                        ? "border-brand text-brand"
                        : "border-transparent text-muted hover:border-line hover:text-ink"
                    }`}
                  >
                    <Icon size={16} />
                    <span>{tab.label}</span>
                    <span
                      className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                        isActive ? "bg-brand/15 text-brand" : "bg-subtle text-muted"
                      }`}
                    >
                      {tab.count}
                    </span>
                  </button>
                );
              })}
            </div>

            <div className="flex items-center gap-2">
              <span className="text-xs text-muted font-medium hidden sm:inline">View:</span>
              <div className="flex rounded-lg border border-line bg-surface p-0.5">
                <button
                  type="button"
                  onClick={() => setViewMode("grid")}
                  className={`rounded-md px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                    viewMode === "grid"
                      ? "bg-brand text-white shadow-xs"
                      : "text-muted hover:text-ink"
                  }`}
                >
                  Cards
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("table")}
                  className={`rounded-md px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                    viewMode === "table"
                      ? "bg-brand text-white shadow-xs"
                      : "text-muted hover:text-ink"
                  }`}
                >
                  Table
                </button>
              </div>
            </div>
          </div>

          {/* Tab Context Banner */}
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-line bg-surface/50 p-4 shadow-xs">
            <div className="flex items-start gap-3">
              <div
                className={`flex size-10 shrink-0 items-center justify-center rounded-xl border ${activeKindConfig.colorClass}`}
              >
                <activeKindConfig.icon size={20} />
              </div>
              <div>
                <h3 className="text-sm font-bold text-ink">{activeKindConfig.plural}</h3>
                <p className="text-xs text-muted leading-relaxed max-w-2xl mt-0.5">
                  {activeKindConfig.description}
                </p>
              </div>
            </div>

            {canManage && (
              <Button
                size="sm"
                variant="outline"
                icon={<PlusIcon size={14} />}
                onClick={() => openCreateModal(activeTab)}
                className="shrink-0 text-xs font-semibold border-brand/30 text-brand hover:bg-brand/10"
              >
                + Add {activeKindConfig.singular}
              </Button>
            )}
          </div>

          {/* Search & Filter Toolbar */}
          <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between">
            <div className="flex-1 max-w-md">
              <div className="relative">
                <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                  <SearchIcon size={16} />
                </span>
                <input
                  type="text"
                  placeholder={`Search ${activeKindConfig.plural.toLowerCase()} by name or code…`}
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  maxLength={60}
                  className="w-full rounded-xl border border-line bg-surface py-2 pl-9 pr-8 text-xs text-ink placeholder:text-muted focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand shadow-xs"
                />
                {search && (
                  <button
                    type="button"
                    onClick={() => setSearch("")}
                    className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted hover:text-ink"
                  >
                    <XIcon size={14} />
                  </button>
                )}
              </div>
            </div>

            {/* Filter pills */}
            <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
              <span className="text-xs font-medium text-muted mr-1">Status:</span>
              {(
                [
                  { id: "all", label: `All (${currentItems.length})` },
                  {
                    id: "active",
                    label: `Active (${currentItems.filter((i) => i.is_active).length})`,
                  },
                  {
                    id: "inactive",
                    label: `Deactivated (${currentItems.filter((i) => !i.is_active).length})`,
                  },
                ] as const
              ).map((f) => (
                <button
                  key={f.id}
                  type="button"
                  onClick={() => setStatusFilter(f.id)}
                  className={`rounded-lg px-2.5 py-1 text-xs font-medium transition cursor-pointer ${
                    statusFilter === f.id
                      ? "bg-brand text-white font-semibold shadow-xs"
                      : "bg-subtle text-muted hover:text-ink hover:bg-line/40"
                  }`}
                >
                  {f.label}
                </button>
              ))}
            </div>
          </div>

          {/* Content Area */}
          {filteredItems.length === 0 ? (
            <div className="rounded-2xl border border-line bg-surface p-10 shadow-xs text-center">
              <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-subtle text-muted mb-3">
                <SearchIcon size={24} />
              </div>
              <h3 className="text-sm font-bold text-ink">
                {search ? `No ${activeKindConfig.plural.toLowerCase()} match "${search}"` : `No ${activeKindConfig.plural.toLowerCase()} found`}
              </h3>
              <p className="text-xs text-muted max-w-sm mx-auto mt-1">
                {search
                  ? "Try searching with a different keyword or resetting your status filter."
                  : `Get started by adding your first ${activeKindConfig.singular.toLowerCase()} to the system.`}
              </p>
              <div className="mt-4 flex justify-center gap-2">
                {search ? (
                  <Button variant="secondary" size="sm" onClick={() => setSearch("")}>
                    Clear search
                  </Button>
                ) : canManage ? (
                  <Button size="sm" icon={<PlusIcon size={14} />} onClick={() => openCreateModal(activeTab)}>
                    Create {activeKindConfig.singular}
                  </Button>
                ) : null}
              </div>
            </div>
          ) : viewMode === "grid" ? (
            /* Card Grid View */
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {filteredItems.map((item) => (
                <div
                  key={item.id}
                  className={`flex flex-col justify-between rounded-2xl border bg-surface p-5 shadow-xs transition duration-150 hover:shadow-md ${
                    item.is_active ? "border-line hover:border-brand/40" : "border-line/60 bg-surface/50 opacity-80"
                  }`}
                >
                  <div>
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex items-start gap-3 min-w-0">
                        <div
                          className={`flex size-10 shrink-0 items-center justify-center rounded-xl border ${activeKindConfig.colorClass}`}
                        >
                          <activeKindConfig.icon size={18} />
                        </div>
                        <div className="min-w-0">
                          <h4 className="text-sm font-bold text-ink truncate">{item.name}</h4>
                          <div className="flex items-center gap-1.5 mt-1">
                            <span className="font-mono text-[11px] text-muted bg-subtle px-1.5 py-0.5 rounded border border-line/60 inline-flex items-center gap-1">
                              <LockIcon size={10} className="text-muted/60" />
                              {item.code}
                            </span>
                          </div>
                        </div>
                      </div>

                      <StatusBadge active={item.is_active} />
                    </div>

                    <p className="mt-3 text-[11px] text-muted leading-relaxed">
                      {activeKindConfig.usageHint}
                    </p>
                  </div>

                  {canManage && (
                    <div className="mt-4 flex items-center justify-between border-t border-line/60 pt-3">
                      <Button
                        size="sm"
                        variant="ghost"
                        icon={<PencilIcon size={12} />}
                        onClick={() => openEditModal(activeTab, item)}
                        className="text-xs text-muted hover:text-ink"
                      >
                        Edit name
                      </Button>

                      <Button
                        size="sm"
                        variant={item.is_active ? "secondary" : "outline"}
                        loading={currentMutation.isPending && currentMutation.variables?.id === item.id}
                        onClick={() => handleToggleActive(activeTab, item)}
                        className={`text-xs font-semibold ${
                          item.is_active
                            ? "text-muted hover:text-danger hover:border-danger/30"
                            : "text-brand hover:bg-brand/10 border-brand/30"
                        }`}
                      >
                        {item.is_active ? "Deactivate" : "Activate"}
                      </Button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          ) : (
            /* Table View */
            <div className="overflow-hidden rounded-2xl border border-line bg-surface shadow-xs">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b border-line bg-subtle/50 text-muted font-bold uppercase tracking-wider text-[11px]">
                    <th className="px-4 py-3">{activeKindConfig.singular}</th>
                    <th className="px-4 py-3">Identifier Code</th>
                    <th className="px-4 py-3">Usage Purpose</th>
                    <th className="px-4 py-3">Status</th>
                    {canManage && <th className="px-4 py-3 text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-line/60">
                  {filteredItems.map((item) => (
                    <tr
                      key={item.id}
                      className={`hover:bg-subtle/30 transition ${
                        !item.is_active ? "bg-subtle/10 opacity-75" : ""
                      }`}
                    >
                      <td className="px-4 py-3.5">
                        <div className="flex items-center gap-2.5">
                          <div
                            className={`flex size-7 shrink-0 items-center justify-center rounded-lg border ${activeKindConfig.colorClass}`}
                          >
                            <activeKindConfig.icon size={14} />
                          </div>
                          <span className="font-bold text-sm text-ink">{item.name}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <span className="font-mono text-xs text-muted bg-subtle px-2 py-0.5 rounded border border-line/60 inline-flex items-center gap-1.5">
                          <LockIcon size={11} className="text-muted/60" />
                          {item.code}
                        </span>
                      </td>
                      <td className="px-4 py-3.5 text-muted text-xs max-w-xs truncate">
                        {activeKindConfig.usageHint}
                      </td>
                      <td className="px-4 py-3.5">
                        <StatusBadge active={item.is_active} />
                      </td>
                      {canManage && (
                        <td className="px-4 py-3.5 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <Button
                              size="sm"
                              variant="ghost"
                              icon={<PencilIcon size={12} />}
                              onClick={() => openEditModal(activeTab, item)}
                              className="text-xs text-muted hover:text-ink"
                            >
                              Edit
                            </Button>
                            <Button
                              size="sm"
                              variant={item.is_active ? "secondary" : "outline"}
                              loading={currentMutation.isPending && currentMutation.variables?.id === item.id}
                              onClick={() => handleToggleActive(activeTab, item)}
                              className={`text-xs font-semibold ${
                                item.is_active
                                  ? "text-muted hover:text-danger hover:border-danger/30"
                                  : "text-brand hover:bg-brand/10 border-brand/30"
                              }`}
                            >
                              {item.is_active ? "Deactivate" : "Activate"}
                            </Button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Modal: Add New Entry */}
        <Modal
          open={isCreateOpen}
          title={`Add New ${KIND_CONFIGS[createKind].singular}`}
          onClose={() => setIsCreateOpen(false)}
        >
          <form noValidate onSubmit={handleCreateSubmit} className="flex flex-col gap-4">
            <FormError message={createApiError && !createApiError.isValidation ? createApiError.message : null} />

            <SelectField
              label="Classification Type"
              options={[
                { value: "product-categories", label: "Garment Category (Apparel Model)" },
                { value: "order-types", label: "Order Type (Intake Routing)" },
                { value: "materials", label: "Fabric Material (Manufacturing Textile)" },
              ]}
              value={createKind}
              onChange={(e) => {
                const k = e.target.value as CatalogKind;
                setCreateKind(k);
              }}
            />

            <TextField
              label="Display Name *"
              placeholder={KIND_CONFIGS[createKind].namePlaceholder}
              maxLength={80}
              value={createForm.name}
              onChange={(e) => {
                const name = e.target.value;
                setCreateForm((prev) => ({
                  ...prev,
                  name,
                  code: prev.codeEdited ? prev.code : slugify(name),
                }));
              }}
              error={createFieldErrors.name?.[0]}
            />

            <div>
              <TextField
                label="System Identifier Code *"
                placeholder={KIND_CONFIGS[createKind].codePlaceholder}
                maxLength={30}
                value={createForm.code}
                hint="Permanent identifier used in database and pricing formulas. Lowercase letters, numbers and underscores only."
                onChange={(e) => {
                  setCreateForm((prev) => ({
                    ...prev,
                    code: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ""),
                    codeEdited: true,
                  }));
                }}
                error={createFieldErrors.code?.[0]}
              />
              {!createForm.codeEdited && createForm.code && (
                <p className="mt-1 text-[11px] text-brand">
                  ⚡ Auto-suggested from display name. Click code field to customize.
                </p>
              )}
            </div>

            <div className="rounded-xl border border-line bg-subtle/40 p-3">
              <Checkbox
                label="Active immediately"
                description="Make this catalog entry immediately available for order intake dropdowns."
                checked={createForm.is_active}
                onChange={(e) =>
                  setCreateForm((prev) => ({ ...prev, is_active: e.target.checked }))
                }
              />
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t border-line">
              <Button variant="secondary" onClick={() => setIsCreateOpen(false)}>
                Cancel
              </Button>
              <Button
                type="submit"
                loading={createMutation.isPending}
                disabled={!createForm.name.trim() || !createForm.code.trim()}
              >
                Create Entry
              </Button>
            </div>
          </form>
        </Modal>

        {/* Modal: Quick Edit Name */}
        <Modal
          open={editItem !== null}
          title={`Edit ${editItem ? KIND_CONFIGS[editItem.kind].singular : "Item"}`}
          onClose={() => setEditItem(null)}
        >
          {editItem && (
            <form noValidate onSubmit={handleEditSubmit} className="flex flex-col gap-4">
              <FormError message={editApiError && !editApiError.isValidation ? editApiError.message : null} />

              <div className="rounded-xl border border-line bg-subtle/30 p-3 flex items-center justify-between">
                <div>
                  <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                    System Identifier Code
                  </span>
                  <div className="flex items-center gap-1.5 mt-0.5">
                    <LockIcon size={12} className="text-muted" />
                    <span className="font-mono text-xs font-bold text-ink">
                      {editItem.item.code}
                    </span>
                  </div>
                </div>
                <Badge tone="neutral">Permanent Code</Badge>
              </div>

              <TextField
                label="Display Name *"
                placeholder="e.g. Polo T-Shirt"
                maxLength={80}
                value={editForm.name}
                onChange={(e) => setEditForm((prev) => ({ ...prev, name: e.target.value }))}
                error={editFieldErrors.name?.[0]}
              />

              <div className="rounded-xl border border-line bg-subtle/40 p-3">
                <Checkbox
                  label="Active Status"
                  description="When inactive, this item remains in historical orders but cannot be selected for new orders."
                  checked={editForm.is_active}
                  onChange={(e) =>
                    setEditForm((prev) => ({ ...prev, is_active: e.target.checked }))
                  }
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-line">
                <Button variant="secondary" onClick={() => setEditItem(null)}>
                  Cancel
                </Button>
                <Button
                  type="submit"
                  loading={editMutation?.isPending}
                  disabled={!editForm.name.trim()}
                >
                  Save Changes
                </Button>
              </div>
            </form>
          )}
        </Modal>
      </div>
    </RequirePermission>
  );
}
