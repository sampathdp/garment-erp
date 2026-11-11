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
  AlertTriangleIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  LayersIcon,
  PlusIcon,
  SearchIcon,
  ShirtIcon,
  SlidersIcon,
  TagIcon,
  XIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { useAuth } from "@/features/auth/auth-provider";
import { ApiError } from "@/lib/api-client";
import { type GarmentSetup, type SetupField, useGarmentSetup, useSetupMutation } from "./api";

const errorText = (e: unknown) =>
  e instanceof ApiError ? [e.message, ...Object.values(e.fieldErrors).flat()].slice(0, 4).join(" ") : null;

function slugify(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 40);
}

type TabKey = "categories" | "option-sets" | "size-charts";

export function GarmentSetupScreen() {
  const query = useGarmentSetup();
  const mutate = useSetupMutation();
  const { can } = useAuth();
  const { notify } = useToast();
  const canManage = can("pricing.manage");

  const [activeTab, setActiveTab] = useState<TabKey>("categories");
  const [selectedCatId, setSelectedCatId] = useState<number | null>(null);

  // Search filters
  const [optionSetSearch, setOptionSetSearch] = useState("");
  const [fieldSearch, setFieldSearch] = useState("");

  // Modals state
  const [isAddFieldOpen, setIsAddFieldOpen] = useState(false);
  const [isAddOptionSetOpen, setIsAddOptionSetOpen] = useState(false);
  const [isAddSizeChartOpen, setIsAddSizeChartOpen] = useState(false);

  // Field creation form
  const [fieldForm, setFieldForm] = useState({
    product_type_id: "",
    key: "",
    keyEdited: false,
    label: "",
    input_type: "text" as SetupField["input_type"],
    garment_option_set_id: "",
    max_length: "",
    is_required: false,
    affects_price: false,
    warn_on_duplicate: false,
  });

  // Option Set creation form
  const [newOptionSet, setNewOptionSet] = useState({ name: "", code: "", codeEdited: false });

  // Size Chart creation form
  const [newSizeChartName, setNewSizeChartName] = useState("");

  // Inline option adding state: map of set.id -> { code, label, codeEdited }
  const [optionInputs, setOptionInputs] = useState<Record<number, { code: string; label: string; codeEdited?: boolean }>>({});

  // Inline size adding state: map of chart.id -> { code, label }
  const [sizeInputs, setSizeInputs] = useState<Record<number, { code: string; label: string }>>({});

  if (query.isPending) return <LoadingState label="Loading garment configurations…" />;
  if (query.error) return <ErrorState error={query.error} />;

  const d = query.data;

  // Set default selected category if none selected
  const activeCategoryId =
    selectedCatId !== null && d.categories.some((c) => c.id === selectedCatId)
      ? selectedCatId
      : d.categories[0]?.id ?? null;

  const currentCategory = d.categories.find((c) => c.id === activeCategoryId);

  // Helper to trigger API mutations
  const run = (method: "post" | "patch", path: string, body: object, onSuccess?: () => void) => {
    mutate.mutate(
      { method, path, body },
      {
        onSuccess: () => {
          notify("Configuration saved successfully", "success");
          onSuccess?.();
        },
      },
    );
  };

  // Open "Add Field" modal pre-targeted to current category
  const openAddFieldModal = () => {
    setFieldForm({
      product_type_id: String(activeCategoryId ?? ""),
      key: "",
      keyEdited: false,
      label: "",
      input_type: "text",
      garment_option_set_id: "",
      max_length: "",
      is_required: false,
      affects_price: false,
      warn_on_duplicate: false,
    });
    setIsAddFieldOpen(true);
  };

  // Save new field
  const handleCreateField = (e: React.FormEvent) => {
    e.preventDefault();
    if (!fieldForm.label.trim()) {
      notify("Please enter a field label", "error");
      return;
    }
    const finalKey = fieldForm.key.trim() || slugify(fieldForm.label);
    if (!finalKey) {
      notify("Please specify a valid field identifier key", "error");
      return;
    }
    if (fieldForm.input_type === "select" && !fieldForm.garment_option_set_id) {
      notify("Please select an option set for this dropdown field", "error");
      return;
    }

    run(
      "post",
      "garment-setup/fields",
      {
        product_type_id: Number(fieldForm.product_type_id),
        key: finalKey,
        label: fieldForm.label.trim(),
        input_type: fieldForm.input_type,
        garment_option_set_id: fieldForm.input_type === "select" ? Number(fieldForm.garment_option_set_id) : null,
        max_length: fieldForm.max_length ? Number(fieldForm.max_length) : null,
        is_required: fieldForm.is_required,
        affects_price: fieldForm.affects_price,
        warn_on_duplicate: fieldForm.warn_on_duplicate,
      },
      () => setIsAddFieldOpen(false),
    );
  };

  // Save new option set
  const handleCreateOptionSet = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newOptionSet.name.trim()) return;
    const finalCode = newOptionSet.code.trim() || slugify(newOptionSet.name);

    run(
      "post",
      "garment-setup/option-sets",
      { code: finalCode, name: newOptionSet.name.trim() },
      () => {
        setNewOptionSet({ name: "", code: "", codeEdited: false });
        setIsAddOptionSetOpen(false);
      },
    );
  };

  // Save new size chart
  const handleCreateSizeChart = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newSizeChartName.trim()) return;

    run(
      "post",
      "garment-setup/size-charts",
      { name: newSizeChartName.trim() },
      () => {
        setNewSizeChartName("");
        setIsAddSizeChartOpen(false);
      },
    );
  };

  // Quick-add an option to an existing set
  const handleQuickAddOption = (setId: number) => {
    const input = optionInputs[setId];
    if (!input?.label?.trim()) return;
    const finalCode = input.code?.trim() || slugify(input.label);

    run(
      "post",
      `garment-setup/option-sets/${setId}/options`,
      { code: finalCode, label: input.label.trim() },
      () => {
        setOptionInputs((prev) => ({ ...prev, [setId]: { code: "", label: "", codeEdited: false } }));
      },
    );
  };

  // Quick-add a size to an existing chart
  const handleQuickAddSize = (chartId: number, currentLength: number) => {
    const input = sizeInputs[chartId];
    if (!input?.code?.trim()) return;

    run(
      "post",
      `garment-setup/size-charts/${chartId}/sizes`,
      {
        code: input.code.trim().toUpperCase(),
        label: input.label?.trim() || input.code.trim().toUpperCase(),
        sort_order: currentLength,
      },
      () => {
        setSizeInputs((prev) => ({ ...prev, [chartId]: { code: "", label: "" } }));
      },
    );
  };

  // Total summary calculations
  const totalFieldsCount = d.categories.reduce((acc, c) => acc + c.fields.length, 0);
  const totalOptionsCount = d.option_sets.reduce((acc, s) => acc + s.options.length, 0);

  // Filtered fields for selected category
  const filteredCategoryFields = (currentCategory?.fields ?? []).filter((f) => {
    if (!fieldSearch.trim()) return true;
    const q = fieldSearch.toLowerCase();
    return f.label.toLowerCase().includes(q) || f.key.toLowerCase().includes(q);
  });

  // Filtered option sets
  const filteredOptionSets = d.option_sets.filter((s) => {
    if (!optionSetSearch.trim()) return true;
    const q = optionSetSearch.toLowerCase();
    return s.name.toLowerCase().includes(q) || s.code.toLowerCase().includes(q);
  });

  return (
    <RequirePermission anyOf={["pricing.view", "pricing.manage", "garment_lists.view"]}>
      <div className="flex flex-col gap-6">
        {/* Top Header */}
        <PageHeader
          title="Name & Number List Setup"
          description="Configure dynamic roster fields, reusable dropdown option sets, and standard sizing scales. All adjustments reflect instantly in order intake and manufacturing rosters."
          actions={
            canManage ? (
              <div className="flex flex-wrap items-center gap-2">
                {activeTab === "categories" && (
                  <Button
                    size="md"
                    icon={<PlusIcon size={14} />}
                    onClick={openAddFieldModal}
                  >
                    Add Custom Field
                  </Button>
                )}
                {activeTab === "option-sets" && (
                  <Button
                    size="md"
                    icon={<PlusIcon size={14} />}
                    onClick={() => setIsAddOptionSetOpen(true)}
                  >
                    New Option Set
                  </Button>
                )}
                {activeTab === "size-charts" && (
                  <Button
                    size="md"
                    icon={<PlusIcon size={14} />}
                    onClick={() => setIsAddSizeChartOpen(true)}
                  >
                    New Size Chart
                  </Button>
                )}
              </div>
            ) : undefined
          }
        />

        <FormError message={errorText(mutate.error)} />

        {/* Executive Summary Stats Ribbon */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-4 shadow-xs">
            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
              <ShirtIcon size={14} className="text-brand" />
              Apparel Categories
            </span>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold tracking-tight text-ink">{d.categories.length}</span>
              <span className="text-xs text-muted">Active styles</span>
            </div>
          </div>

          <div className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-4 shadow-xs">
            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
              <LayersIcon size={14} className="text-brand" />
              Roster Fields
            </span>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold tracking-tight text-ink">{totalFieldsCount}</span>
              <span className="text-xs text-muted">Configured fields</span>
            </div>
          </div>

          <div className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-4 shadow-xs">
            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
              <SlidersIcon size={14} className="text-brand" />
              Dropdown Sets
            </span>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold tracking-tight text-ink">{d.option_sets.length}</span>
              <span className="text-xs text-muted">({totalOptionsCount} values)</span>
            </div>
          </div>

          <div className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-4 shadow-xs">
            <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
              <TagIcon size={14} className="text-brand" />
              Size Profiles
            </span>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold tracking-tight text-ink">{d.size_charts.length}</span>
              <span className="text-xs text-muted">Charts defined</span>
            </div>
          </div>
        </div>

        {/* Tab Navigation Ribbon */}
        <div className="flex items-center border-b border-line">
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => setActiveTab("categories")}
              className={`flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition-all ${
                activeTab === "categories"
                  ? "border-brand text-brand"
                  : "border-transparent text-muted hover:border-line hover:text-ink"
              }`}
            >
              <ShirtIcon size={16} />
              <span>Garment Categories & Fields</span>
              <span className="rounded-full bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
                {d.categories.length}
              </span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab("option-sets")}
              className={`flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition-all ${
                activeTab === "option-sets"
                  ? "border-brand text-brand"
                  : "border-transparent text-muted hover:border-line hover:text-ink"
              }`}
            >
              <SlidersIcon size={16} />
              <span>Dropdown Option Sets</span>
              <span className="rounded-full bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
                {d.option_sets.length}
              </span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab("size-charts")}
              className={`flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition-all ${
                activeTab === "size-charts"
                  ? "border-brand text-brand"
                  : "border-transparent text-muted hover:border-line hover:text-ink"
              }`}
            >
              <TagIcon size={16} />
              <span>Size Charts & Scales</span>
              <span className="rounded-full bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
                {d.size_charts.length}
              </span>
            </button>
          </div>
        </div>

        {/* -------------------- TAB 1: CATEGORIES & FIELDS -------------------- */}
        {activeTab === "categories" && (
          <div className="flex flex-col gap-5">
            {/* Horizontal Category Switcher */}
            <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-line bg-surface p-2 shadow-xs">
              <span className="px-3 text-xs font-semibold uppercase tracking-wider text-muted">
                Category:
              </span>
              {d.categories.map((c) => {
                const isSelected = c.id === activeCategoryId;
                return (
                  <button
                    key={c.id}
                    type="button"
                    onClick={() => {
                      setSelectedCatId(c.id);
                      setFieldSearch("");
                    }}
                    className={`flex items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-medium transition-all ${
                      isSelected
                        ? "bg-brand text-white shadow-xs font-semibold"
                        : "bg-subtle/60 text-ink hover:bg-subtle hover:text-ink"
                    }`}
                  >
                    <span>{c.name}</span>
                    <span
                      className={`rounded-full px-1.5 py-0.2 text-[10px] font-bold ${
                        isSelected ? "bg-white/20 text-white" : "bg-white text-muted border border-line/60"
                      }`}
                    >
                      {c.fields.length}
                    </span>
                  </button>
                );
              })}
            </div>

            {/* Category Workspace Card */}
            {currentCategory && (
              <div className="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5 shadow-xs">
                {/* Category Header & Size Chart Linking */}
                <div className="flex flex-wrap items-center justify-between gap-4 border-b border-line pb-4">
                  <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2.5">
                      <h2 className="text-lg font-bold text-ink">{currentCategory.name}</h2>
                      <span className="font-mono text-xs text-muted bg-subtle px-2 py-0.5 rounded-md border border-line">
                        code: {currentCategory.code}
                      </span>
                      <StatusBadge active={currentCategory.is_active} />
                    </div>
                    <p className="text-xs text-muted">
                      Configure custom specifications collected for {currentCategory.name} orders (e.g. Player Names, Numbers, Collars).
                    </p>
                  </div>

                  {/* Size Chart Binding Box */}
                  <div className="flex items-center gap-3 rounded-xl border border-line/80 bg-subtle/40 p-2.5">
                    <TagIcon size={16} className="text-brand shrink-0" />
                    <div className="flex flex-col">
                      <span className="text-[10px] font-semibold uppercase tracking-wider text-muted">
                        Assigned Size Chart
                      </span>
                      {canManage ? (
                        <select
                          className="mt-0.5 rounded border border-line bg-surface py-0.5 px-2 text-xs font-medium text-ink focus:border-brand focus:outline-none"
                          value={String(currentCategory.size_chart_id)}
                          onChange={(e) =>
                            run("patch", `garment-setup/categories/${currentCategory.id}`, {
                              size_chart_id: Number(e.target.value),
                            })
                          }
                        >
                          {d.size_charts.map((s) => (
                            <option key={s.id} value={String(s.id)}>
                              {s.name} ({s.sizes.length} sizes)
                            </option>
                          ))}
                        </select>
                      ) : (
                        <span className="text-xs font-medium text-ink">
                          {d.size_charts.find((s) => s.id === currentCategory.size_chart_id)?.name ?? "—"}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Search & Actions Bar */}
                <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                  <div className="relative flex items-center w-full sm:w-72">
                    <div className="pointer-events-none absolute left-3 text-muted">
                      <SearchIcon size={14} />
                    </div>
                    <input
                      type="text"
                      placeholder="Search fields by name or key…"
                      value={fieldSearch}
                      onChange={(e) => setFieldSearch(e.target.value)}
                      className="h-9 w-full rounded-lg border border-line bg-surface pl-9 pr-3 text-xs text-ink placeholder:text-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20 shadow-xs"
                    />
                  </div>
                  {canManage && (
                    <Button
                      size="sm"
                      icon={<PlusIcon size={14} />}
                      onClick={openAddFieldModal}
                    >
                      Add Field to {currentCategory.name}
                    </Button>
                  )}
                </div>

                {/* Fields Table */}
                {filteredCategoryFields.length === 0 ? (
                  <EmptyState
                    title={fieldSearch ? "No matching fields found" : "No custom fields configured yet"}
                    description={
                      fieldSearch
                        ? "Try adjusting your search keyword."
                        : `Add custom fields to collect specifications when players or batches of ${currentCategory.name} are added.`
                    }
                    action={
                      canManage && !fieldSearch ? (
                        <Button size="sm" icon={<PlusIcon size={14} />} onClick={openAddFieldModal}>
                          Add First Field
                        </Button>
                      ) : undefined
                    }
                  />
                ) : (
                  <div className="overflow-x-auto rounded-xl border border-line">
                    <table className="w-full border-collapse text-left text-xs">
                      <thead>
                        <tr className="border-b border-line bg-subtle/50 text-muted font-semibold uppercase tracking-wider">
                          <th className="py-3 px-4">Field Specification</th>
                          <th className="py-3 px-4">Input Type & Options</th>
                          <th className="py-3 px-4">Behavior Rules</th>
                          <th className="py-3 px-4 text-center">Status</th>
                          {canManage && <th className="py-3 px-4 text-right">Actions</th>}
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-line/60 bg-surface">
                        {filteredCategoryFields.map((f) => {
                          const linkedSet = f.garment_option_set_id
                            ? d.option_sets.find((s) => s.id === f.garment_option_set_id)
                            : null;

                          return (
                            <tr key={f.id} className="hover:bg-subtle/30 transition-colors">
                              {/* Field Spec */}
                              <td className="py-3.5 px-4">
                                <div className="flex flex-col gap-0.5">
                                  <span className="font-semibold text-sm text-ink">{f.label}</span>
                                  <div className="flex items-center gap-2">
                                    <span className="font-mono text-[11px] text-muted">
                                      {f.key}
                                    </span>
                                    {f.max_length && (
                                      <span className="text-[10px] text-muted bg-subtle px-1 rounded">
                                        max {f.max_length} chars
                                      </span>
                                    )}
                                  </div>
                                </div>
                              </td>

                              {/* Input Type */}
                              <td className="py-3.5 px-4">
                                <div className="flex flex-col gap-1">
                                  <div className="flex items-center gap-1.5">
                                    <span className="rounded-md border border-line bg-subtle px-2 py-0.5 font-mono text-[11px] font-medium text-ink capitalize">
                                      {f.input_type}
                                    </span>
                                  </div>
                                  {f.input_type === "select" && (
                                    <span className="text-[11px] text-muted">
                                      {linkedSet ? (
                                        <span className="text-brand font-medium">
                                          {linkedSet.name} ({linkedSet.options.length} options)
                                        </span>
                                      ) : (
                                        <span className="text-amber-600">No option set bound</span>
                                      )}
                                    </span>
                                  )}
                                </div>
                              </td>

                              {/* Behavior Rules */}
                              <td className="py-3.5 px-4">
                                <div className="flex flex-wrap items-center gap-1.5">
                                  {/* Required toggle */}
                                  {canManage ? (
                                    <button
                                      type="button"
                                      title="Click to toggle required"
                                      onClick={() =>
                                        run("patch", `garment-setup/fields/${f.id}`, {
                                          is_required: !f.is_required,
                                        })
                                      }
                                      className={`rounded-full px-2 py-0.5 text-[11px] font-semibold transition-all cursor-pointer ${
                                        f.is_required
                                          ? "bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100"
                                          : "bg-subtle text-muted border border-line hover:bg-line/60"
                                      }`}
                                    >
                                      {f.is_required ? "✓ Required" : "Optional"}
                                    </button>
                                  ) : f.is_required ? (
                                    <Badge tone="brand">Required</Badge>
                                  ) : (
                                    <span className="text-muted text-[11px]">Optional</span>
                                  )}

                                  {/* Affects Price toggle */}
                                  {canManage ? (
                                    <button
                                      type="button"
                                      title="Click to toggle price rule integration"
                                      onClick={() =>
                                        run("patch", `garment-setup/fields/${f.id}`, {
                                          affects_price: !f.affects_price,
                                        })
                                      }
                                      className={`rounded-full px-2 py-0.5 text-[11px] font-semibold transition-all cursor-pointer ${
                                        f.affects_price
                                          ? "bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-100"
                                          : "bg-subtle/50 text-muted/70 border border-line/60 hover:bg-line/60"
                                      }`}
                                    >
                                      {f.affects_price ? "💰 Affects Price" : "No Price Impact"}
                                    </button>
                                  ) : (
                                    f.affects_price && <Badge tone="warning">Affects Price</Badge>
                                  )}

                                  {/* Warn on duplicate */}
                                  {canManage ? (
                                    <button
                                      type="button"
                                      title="Click to toggle duplicate alert"
                                      onClick={() =>
                                        run("patch", `garment-setup/fields/${f.id}`, {
                                          warn_on_duplicate: !f.warn_on_duplicate,
                                        })
                                      }
                                      className={`rounded-full px-2 py-0.5 text-[11px] font-semibold transition-all cursor-pointer ${
                                        f.warn_on_duplicate
                                          ? "bg-purple-50 text-purple-700 border border-purple-200 hover:bg-purple-100"
                                          : "bg-subtle/50 text-muted/70 border border-line/60 hover:bg-line/60"
                                      }`}
                                    >
                                      {f.warn_on_duplicate ? "⚠️ Dup Warning" : "No Dup Check"}
                                    </button>
                                  ) : (
                                    f.warn_on_duplicate && <Badge tone="neutral">Dup Warning</Badge>
                                  )}
                                </div>
                              </td>

                              {/* Status */}
                              <td className="py-3.5 px-4 text-center">
                                <StatusBadge active={f.is_active} />
                              </td>

                              {/* Actions */}
                              {canManage && (
                                <td className="py-3.5 px-4 text-right">
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                      run("patch", `garment-setup/fields/${f.id}`, {
                                        is_active: !f.is_active,
                                      })
                                    }
                                  >
                                    {f.is_active ? "Deactivate" : "Activate"}
                                  </Button>
                                </td>
                              )}
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* -------------------- TAB 2: DROPDOWN OPTION SETS -------------------- */}
        {activeTab === "option-sets" && (
          <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="relative flex items-center w-full sm:w-80">
                <div className="pointer-events-none absolute left-3 text-muted">
                  <SearchIcon size={14} />
                </div>
                <input
                  type="text"
                  placeholder="Filter option sets by name or code…"
                  value={optionSetSearch}
                  onChange={(e) => setOptionSetSearch(e.target.value)}
                  className="h-9 w-full rounded-lg border border-line bg-surface pl-9 pr-3 text-xs text-ink placeholder:text-muted/60 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20 shadow-xs"
                />
              </div>
              {canManage && (
                <Button
                  size="md"
                  icon={<PlusIcon size={14} />}
                  onClick={() => setIsAddOptionSetOpen(true)}
                >
                  Create New Option Set
                </Button>
              )}
            </div>

            {filteredOptionSets.length === 0 ? (
              <EmptyState
                title="No option sets found"
                description={
                  optionSetSearch
                    ? "No option sets match your search filter."
                    : "Create reusable option sets like Collar Styles, Sleeve Types, or Pocket Designs for garment fields."
                }
                action={
                  canManage && !optionSetSearch ? (
                    <Button
                      size="sm"
                      icon={<PlusIcon size={14} />}
                      onClick={() => setIsAddOptionSetOpen(true)}
                    >
                      Create Option Set
                    </Button>
                  ) : undefined
                }
              />
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {filteredOptionSets.map((s) => {
                  const quickInput = optionInputs[s.id] || { code: "", label: "" };
                  const activeCount = s.options.filter((o) => o.is_active).length;

                  return (
                    <div
                      key={s.id}
                      className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-4 shadow-xs transition-shadow hover:shadow-sm"
                    >
                      <div className="flex flex-col gap-3">
                        {/* Header */}
                        <div className="flex items-center justify-between gap-2 border-b border-line pb-2.5">
                          <div className="flex flex-col">
                            <h3 className="font-bold text-sm text-ink">{s.name}</h3>
                            <span className="font-mono text-xs text-muted">{s.code}</span>
                          </div>
                          <Badge tone={activeCount > 0 ? "brand" : "neutral"}>
                            {activeCount} active
                          </Badge>
                        </div>

                        {/* Options Chip List */}
                        <div className="flex flex-col gap-1.5 min-h-[90px]">
                          <span className="text-[10px] font-semibold uppercase tracking-wider text-muted">
                            Values ({s.options.length}):
                          </span>
                          {s.options.length === 0 ? (
                            <p className="text-xs text-muted italic">No options defined yet.</p>
                          ) : (
                            <div className="flex flex-wrap gap-1.5">
                              {s.options.map((o) => (
                                <div
                                  key={o.id}
                                  className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-colors ${
                                    o.is_active
                                      ? "border-line bg-surface text-ink shadow-2xs"
                                      : "border-line/60 bg-subtle/50 text-muted line-through"
                                  }`}
                                >
                                  <span className="font-medium">{o.label}</span>
                                  {canManage && (
                                    <button
                                      type="button"
                                      title={o.is_active ? "Disable option" : "Enable option"}
                                      className={`text-[10px] font-bold cursor-pointer ${
                                        o.is_active
                                          ? "text-muted hover:text-danger"
                                          : "text-brand hover:underline"
                                      }`}
                                      onClick={() =>
                                        run("patch", `garment-setup/options/${o.id}`, {
                                          is_active: !o.is_active,
                                        })
                                      }
                                    >
                                      {o.is_active ? "×" : "activate"}
                                    </button>
                                  )}
                                </div>
                              ))}
                            </div>
                          )}
                        </div>
                      </div>

                      {/* In-Card Quick-Add Option Footer */}
                      {canManage && (
                        <div className="mt-4 border-t border-line/60 pt-3 flex flex-col gap-2">
                          <span className="text-[10px] font-semibold uppercase tracking-wider text-muted">
                            Add Option to Set
                          </span>
                          <div className="flex gap-2">
                            <input
                              type="text"
                              placeholder="Display label (e.g. V-Neck)"
                              value={quickInput.label}
                              onChange={(e) => {
                                const val = e.target.value;
                                setOptionInputs((prev) => ({
                                  ...prev,
                                  [s.id]: {
                                    label: val,
                                    code: prev[s.id]?.codeEdited ? prev[s.id].code : slugify(val),
                                    codeEdited: prev[s.id]?.codeEdited,
                                  },
                                }));
                              }}
                              className="w-full rounded-lg border border-line bg-surface px-2.5 py-1 text-xs text-ink placeholder:text-muted focus:border-brand focus:outline-none"
                            />
                            <Button
                              size="sm"
                              icon={<PlusIcon size={12} />}
                              onClick={() => handleQuickAddOption(s.id)}
                            >
                              Add
                            </Button>
                          </div>
                          {quickInput.label && (
                            <div className="flex items-center gap-1.5 text-[10px] text-muted">
                              <span>Code:</span>
                              <input
                                type="text"
                                value={quickInput.code}
                                onChange={(e) =>
                                  setOptionInputs((prev) => ({
                                    ...prev,
                                    [s.id]: {
                                      ...prev[s.id],
                                      code: e.target.value.toLowerCase(),
                                      codeEdited: true,
                                    },
                                  }))
                                }
                                className="font-mono border-b border-dashed border-line bg-transparent px-1 focus:outline-none focus:border-brand"
                              />
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        )}

        {/* -------------------- TAB 3: SIZE CHARTS & SCALES -------------------- */}
        {activeTab === "size-charts" && (
          <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-xs text-muted max-w-xl">
                Size charts define standardized sizing scales (e.g., standard adult, kids, oversized)
                mapped to apparel categories during order roster configuration.
              </p>
              {canManage && (
                <Button
                  size="md"
                  icon={<PlusIcon size={14} />}
                  onClick={() => setIsAddSizeChartOpen(true)}
                >
                  Create New Size Chart
                </Button>
              )}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              {d.size_charts.map((c) => {
                const quickSize = sizeInputs[c.id] || { code: "", label: "" };
                const boundCategories = d.categories.filter((cat) => cat.size_chart_id === c.id);

                return (
                  <div
                    key={c.id}
                    className="flex flex-col justify-between rounded-2xl border border-line bg-surface p-5 shadow-xs transition-shadow hover:shadow-sm"
                  >
                    <div className="flex flex-col gap-4">
                      {/* Header */}
                      <div className="flex items-center justify-between gap-2 border-b border-line pb-3">
                        <div className="flex flex-col">
                          <h3 className="font-bold text-base text-ink">{c.name}</h3>
                          <span className="text-xs text-muted">
                            {boundCategories.length > 0 ? (
                              <span>
                                Used by:{" "}
                                <strong className="text-ink font-medium">
                                  {boundCategories.map((b) => b.name).join(", ")}
                                </strong>
                              </span>
                            ) : (
                              <span className="text-amber-700">Not assigned to any category</span>
                            )}
                          </span>
                        </div>
                        <Badge tone="brand">{c.sizes.length} sizes</Badge>
                      </div>

                      {/* Sizing Sequence */}
                      <div className="flex flex-col gap-2">
                        <span className="text-[10px] font-semibold uppercase tracking-wider text-muted">
                          Sizing Sequence ({c.sizes.length}):
                        </span>
                        {c.sizes.length === 0 ? (
                          <p className="text-xs text-danger">No sizes configured for this chart yet.</p>
                        ) : (
                          <div className="flex flex-wrap gap-2">
                            {c.sizes.map((s, idx) => (
                              <div
                                key={s.id}
                                className="group relative flex items-center gap-1.5 rounded-xl border border-line bg-subtle/50 px-3 py-1.5 text-xs shadow-2xs"
                              >
                                <span className="font-mono font-bold text-ink">{s.code}</span>
                                {s.label !== s.code && (
                                  <span className="text-[10px] text-muted">({s.label})</span>
                                )}
                                <span className="text-[9px] text-muted/60 font-mono">#{idx + 1}</span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Quick-Add Size Footer */}
                    {canManage && (
                      <div className="mt-5 border-t border-line/60 pt-3 flex flex-col gap-2">
                        <span className="text-[10px] font-semibold uppercase tracking-wider text-muted">
                          Add Size to Scale
                        </span>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                          <input
                            type="text"
                            placeholder="Code (e.g. 3XL)"
                            maxLength={10}
                            value={quickSize.code}
                            onChange={(e) =>
                              setSizeInputs((prev) => ({
                                ...prev,
                                [c.id]: {
                                  ...prev[c.id],
                                  code: e.target.value.toUpperCase(),
                                },
                              }))
                            }
                            className="rounded-lg border border-line bg-surface px-2.5 py-1 text-xs font-mono font-medium text-ink placeholder:font-sans placeholder:text-muted focus:border-brand focus:outline-none"
                          />
                          <input
                            type="text"
                            placeholder="Label (e.g. 3X Large)"
                            maxLength={40}
                            value={quickSize.label}
                            onChange={(e) =>
                              setSizeInputs((prev) => ({
                                ...prev,
                                [c.id]: {
                                  ...prev[c.id],
                                  label: e.target.value,
                                },
                              }))
                            }
                            className="rounded-lg border border-line bg-surface px-2.5 py-1 text-xs text-ink placeholder:text-muted focus:border-brand focus:outline-none"
                          />
                          <div className="col-span-2 sm:col-span-1">
                            <Button
                              size="sm"
                              className="w-full"
                              icon={<PlusIcon size={12} />}
                              onClick={() => handleQuickAddSize(c.id, c.sizes.length)}
                            >
                              Add Size
                            </Button>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* ==================== MODAL: ADD CUSTOM FIELD ==================== */}
        <Modal
          open={isAddFieldOpen}
          title={`Add Custom Field to ${currentCategory?.name ?? "Category"}`}
          onClose={() => setIsAddFieldOpen(false)}
        >
          <form onSubmit={handleCreateField} className="flex flex-col gap-4">
            <p className="text-xs text-muted leading-relaxed">
              Define a new custom attribute collected for every player or item row in this garment roster.
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
              <SelectField
                label="Target Category *"
                options={d.categories.map((c) => ({ value: String(c.id), label: c.name }))}
                value={fieldForm.product_type_id}
                onChange={(e) => setFieldForm({ ...fieldForm, product_type_id: e.target.value })}
              />

              <SelectField
                label="Input Type *"
                options={d.field_types.map((t) => ({ value: t, label: t.toUpperCase() }))}
                value={fieldForm.input_type}
                onChange={(e) =>
                  setFieldForm({
                    ...fieldForm,
                    input_type: e.target.value as SetupField["input_type"],
                  })
                }
              />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <TextField
                label="Display Label *"
                placeholder="e.g. Collar Style"
                maxLength={80}
                value={fieldForm.label}
                onChange={(e) => {
                  const val = e.target.value;
                  setFieldForm({
                    ...fieldForm,
                    label: val,
                    key: fieldForm.keyEdited ? fieldForm.key : slugify(val),
                  });
                }}
              />

              <TextField
                label="System Identifier Key *"
                placeholder="e.g. collar_style"
                maxLength={40}
                hint="Lower-case letters & underscores. Permanent."
                value={fieldForm.key}
                onChange={(e) =>
                  setFieldForm({
                    ...fieldForm,
                    key: e.target.value.toLowerCase(),
                    keyEdited: true,
                  })
                }
              />
            </div>

            {/* If select, require option set */}
            {fieldForm.input_type === "select" && (
              <div className="rounded-xl border border-brand/20 bg-brand/5 p-3">
                <SelectField
                  label="Values from Option Set *"
                  hint="Select which preset dropdown values populate this field"
                  options={[
                    { value: "", label: "Choose an option set" },
                    ...d.option_sets.map((s) => ({
                      value: String(s.id),
                      label: `${s.name} (${s.options.length} options)`,
                    })),
                  ]}
                  value={fieldForm.garment_option_set_id}
                  onChange={(e) =>
                    setFieldForm({ ...fieldForm, garment_option_set_id: e.target.value })
                  }
                />
              </div>
            )}

            {fieldForm.input_type === "text" && (
              <TextField
                label="Maximum Character Length (Optional)"
                type="number"
                placeholder="e.g. 30"
                value={fieldForm.max_length}
                onChange={(e) => setFieldForm({ ...fieldForm, max_length: e.target.value })}
              />
            )}

            {/* Behavior Rules Checkboxes */}
            <div className="flex flex-col gap-2 rounded-xl border border-line bg-subtle/30 p-3">
              <span className="text-xs font-semibold uppercase tracking-wider text-muted">
                Validation & Pricing Rules
              </span>
              <div className="grid gap-2 sm:grid-cols-3">
                <label className="flex items-start gap-2 rounded-lg border border-line bg-surface p-2.5 cursor-pointer hover:bg-subtle/40">
                  <input
                    type="checkbox"
                    className="mt-0.5 rounded border-line text-brand focus:ring-brand"
                    checked={fieldForm.is_required}
                    onChange={(e) => setFieldForm({ ...fieldForm, is_required: e.target.checked })}
                  />
                  <div className="flex flex-col text-xs">
                    <span className="font-semibold text-ink">Mandatory</span>
                    <span className="text-muted text-[11px]">Cannot be left blank</span>
                  </div>
                </label>

                <label className="flex items-start gap-2 rounded-lg border border-line bg-surface p-2.5 cursor-pointer hover:bg-subtle/40">
                  <input
                    type="checkbox"
                    className="mt-0.5 rounded border-line text-brand focus:ring-brand"
                    checked={fieldForm.affects_price}
                    onChange={(e) => setFieldForm({ ...fieldForm, affects_price: e.target.checked })}
                  />
                  <div className="flex flex-col text-xs">
                    <span className="font-semibold text-ink">Affects Price</span>
                    <span className="text-muted text-[11px]">Enables cost matrix rules</span>
                  </div>
                </label>

                <label className="flex items-start gap-2 rounded-lg border border-line bg-surface p-2.5 cursor-pointer hover:bg-subtle/40">
                  <input
                    type="checkbox"
                    className="mt-0.5 rounded border-line text-brand focus:ring-brand"
                    checked={fieldForm.warn_on_duplicate}
                    onChange={(e) =>
                      setFieldForm({ ...fieldForm, warn_on_duplicate: e.target.checked })
                    }
                  />
                  <div className="flex flex-col text-xs">
                    <span className="font-semibold text-ink">Duplicate Check</span>
                    <span className="text-muted text-[11px]">Warns on repeat values</span>
                  </div>
                </label>
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t border-line">
              <Button variant="secondary" onClick={() => setIsAddFieldOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" loading={mutate.isPending}>
                Create Field
              </Button>
            </div>
          </form>
        </Modal>

        {/* ==================== MODAL: CREATE OPTION SET ==================== */}
        <Modal
          open={isAddOptionSetOpen}
          title="Create New Dropdown Option Set"
          onClose={() => setIsAddOptionSetOpen(false)}
        >
          <form onSubmit={handleCreateOptionSet} className="flex flex-col gap-4">
            <p className="text-xs text-muted leading-relaxed">
              Create a reusable set of options (such as Collar Styles, Sleeve Types, or Custom Badges)
              that can be shared across multiple garment categories.
            </p>

            <TextField
              label="Option Set Name *"
              placeholder="e.g. Collar Styles"
              maxLength={80}
              value={newOptionSet.name}
              onChange={(e) => {
                const val = e.target.value;
                setNewOptionSet({
                  ...newOptionSet,
                  name: val,
                  code: newOptionSet.codeEdited ? newOptionSet.code : slugify(val),
                });
              }}
            />

            <TextField
              label="System Code *"
              placeholder="e.g. collar_styles"
              maxLength={40}
              hint="Unique lowercase identifier."
              value={newOptionSet.code}
              onChange={(e) =>
                setNewOptionSet({
                  ...newOptionSet,
                  code: e.target.value.toLowerCase(),
                  codeEdited: true,
                })
              }
            />

            <div className="flex justify-end gap-2 pt-2 border-t border-line">
              <Button variant="secondary" onClick={() => setIsAddOptionSetOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" loading={mutate.isPending}>
                Create Option Set
              </Button>
            </div>
          </form>
        </Modal>

        {/* ==================== MODAL: CREATE SIZE CHART ==================== */}
        <Modal
          open={isAddSizeChartOpen}
          title="Create New Size Chart"
          onClose={() => setIsAddSizeChartOpen(false)}
        >
          <form onSubmit={handleCreateSizeChart} className="flex flex-col gap-4">
            <p className="text-xs text-muted leading-relaxed">
              Create a size scale profile (such as Standard Adult, Kids Sizing, or Women&apos;s Fitted).
              After creating, you can populate sizes into the chart.
            </p>

            <TextField
              label="Size Chart Name *"
              placeholder="e.g. Kids Standard Sizing"
              maxLength={80}
              value={newSizeChartName}
              onChange={(e) => setNewSizeChartName(e.target.value)}
            />

            <div className="flex justify-end gap-2 pt-2 border-t border-line">
              <Button variant="secondary" onClick={() => setIsAddSizeChartOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" loading={mutate.isPending}>
                Create Chart
              </Button>
            </div>
          </form>
        </Modal>
      </div>
    </RequirePermission>
  );
}
