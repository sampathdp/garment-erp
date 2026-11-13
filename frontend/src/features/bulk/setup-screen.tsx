"use client";

import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import {
  Badge,
  Checkbox,
  FormError,
  PageHeader,
  Section,
  SelectField,
  StatusBadge,
  TextAreaField,
} from "@/components/ui/form-controls";
import {
  BuildingIcon,
  CheckCircleIcon,
  CheckIcon,
  CopyIcon,
  FileTextIcon,
  MailIcon,
  PencilIcon,
  PhoneIcon,
  PlusIcon,
  ScissorsIcon,
  SearchIcon,
  ShirtIcon,
  SlidersIcon,
  SparklesIcon,
  XIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { api, ApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { type BulkSetup, useBulkSetup, useBulkSetupMutation } from "./api";

const errorMessage = (e: unknown) =>
  e instanceof ApiError
    ? (Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
    : null;

function slugify(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9_]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 40);
}

/** One checklist item per line; a line starting with "?" is optional. Exported for tests. */
export function parseChecklistLines(text: string): { label: string; is_required: boolean }[] {
  return text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter((l) => l !== "" && l !== "?")
    .map((l) =>
      l.startsWith("?")
        ? { label: l.slice(1).trim(), is_required: false }
        : { label: l, is_required: true },
    );
}

type SetupTab = "fields" | "templates" | "suppliers";

/* =========================================================================
   TAB 1: MEASUREMENT FIELDS
   ========================================================================= */

function MeasurementFieldsTab({ setup }: { setup: BulkSetup }) {
  const { notify } = useToast();
  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [modalOpen, setModalOpen] = useState(false);

  const [form, setForm] = useState({
    key: "",
    label: "",
    unit: "m",
    product_type_id: "",
    min_value: "0.00",
    max_value: "1000.00",
    decimals: "2",
    is_required: false,
    show_on_job_card: true,
  });

  const create = useBulkSetupMutation((body: object) =>
    api.post("bulk-setup/measurement-fields", body),
  );
  const update = useBulkSetupMutation((input: { id: number; body: object }) =>
    api.patch(`bulk-setup/measurement-fields/${input.id}`, input.body),
  );

  const systemFields = useMemo(
    () => setup.measurement_fields.filter((f) => f.is_system),
    [setup.measurement_fields],
  );

  const customFields = useMemo(() => {
    return setup.measurement_fields
      .filter((f) => !f.is_system)
      .filter((f) => {
        if (categoryFilter && String(f.product_type_id ?? "") !== categoryFilter) {
          return false;
        }
        if (search) {
          const q = search.toLowerCase();
          return f.label.toLowerCase().includes(q) || f.key.toLowerCase().includes(q);
        }
        return true;
      });
  }, [setup.measurement_fields, categoryFilter, search]);

  const handleLabelChange = (val: string) => {
    setForm((prev) => ({
      ...prev,
      label: val,
      key: prev.key === slugify(prev.label) || prev.key === "" ? slugify(val) : prev.key,
    }));
  };

  const handleCreate = () => {
    create.mutate(
      {
        ...form,
        product_type_id: form.product_type_id ? Number(form.product_type_id) : null,
        min_value: Number(form.min_value),
        max_value: Number(form.max_value),
        decimals: Number(form.decimals),
      },
      {
        onSuccess: () => {
          notify("Measurement field added", "success");
          setModalOpen(false);
          setForm({
            key: "",
            label: "",
            unit: "m",
            product_type_id: "",
            min_value: "0.00",
            max_value: "1000.00",
            decimals: "2",
            is_required: false,
            show_on_job_card: true,
          });
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-6">
      {/* System Core Measurements Section */}
      <div className="flex flex-col gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h3 className="text-base font-semibold text-ink">System Core Measurements</h3>
            <Badge tone="brand">Factory Standard</Badge>
          </div>
          <p className="mt-0.5 text-xs text-muted">
            Foundational parameters required for automated fabric yardage calculations, consumption per piece, and official Job Traveler printing.
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          {systemFields.map((field) => (
            <div
              key={field.id}
              className="relative flex flex-col justify-between rounded-xl border border-line bg-surface p-5 shadow-xs transition hover:border-brand/40"
            >
              <div>
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <h4 className="text-base font-bold text-ink">{field.label}</h4>
                    <span className="font-mono text-xs text-muted">key: {field.key}</span>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <Badge tone="brand">System</Badge>
                    <Badge tone="success">Required</Badge>
                  </div>
                </div>
                <p className="mt-2 text-xs text-muted leading-relaxed">
                  {field.key === "bulk_meter"
                    ? "Main body roll length measured before sublimation cutting. Required to compute total fabric meterage and consumption per piece."
                    : "Secondary fabric roll meterage for ribbing, arm cuts, neck collars, and custom contrast panels."}
                </p>
              </div>

              <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-line/60 pt-3 text-xs">
                <span className="text-muted">
                  Allowed Range: <strong className="text-ink font-semibold">{field.min_value} – {field.max_value} {field.unit}</strong>
                </span>
                <span className="rounded-md bg-subtle px-2 py-0.5 font-medium text-ink">
                  {field.decimals} Decimals
                </span>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Category Specific & Custom Fields */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div>
            <h3 className="text-base font-semibold text-ink">Category-Specific Fields</h3>
            <p className="text-xs text-muted mt-0.5">
              Custom measurements configured per garment category (e.g. Collar Meter for upper body garments).
            </p>
          </div>
          <Button icon={<PlusIcon size={16} />} onClick={() => setModalOpen(true)}>
            Add Measurement Field
          </Button>
        </div>

        {/* Filters */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          <div className="relative flex-1">
            <SearchIcon size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
            <input
              type="text"
              placeholder="Search fields by label or key..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-9 w-full rounded-lg border border-line bg-surface pl-9 pr-3 text-xs text-ink placeholder:text-muted/60 outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
            />
          </div>
          <select
            value={categoryFilter}
            onChange={(e) => setCategoryFilter(e.target.value)}
            className="h-9 rounded-lg border border-line bg-surface px-3 text-xs text-ink outline-none focus:border-brand cursor-pointer"
          >
            <option value="">All Categories ({setup.measurement_fields.filter((f) => !f.is_system).length})</option>
            {setup.product_types.map((p) => (
              <option key={p.id} value={String(p.id)}>
                {p.name}
              </option>
            ))}
          </select>
        </div>

        {/* Custom Fields List */}
        {customFields.length === 0 ? (
          <div className="rounded-xl border border-line bg-surface/50 p-8 text-center">
            <SlidersIcon size={24} className="mx-auto text-muted mb-2 opacity-50" />
            <p className="text-sm font-semibold text-ink">No custom measurement fields</p>
            <p className="text-xs text-muted mt-1 max-w-sm mx-auto">
              {search || categoryFilter
                ? "No fields match your current search or category filter."
                : "Add optional or required category measurements such as Collar Meter or Pocket Meter."}
            </p>
          </div>
        ) : (
          <div className="overflow-hidden rounded-xl border border-line bg-surface shadow-xs">
            <table className="w-full text-left text-xs">
              <thead className="border-b border-line bg-subtle text-muted">
                <tr>
                  <th className="px-4 py-3 font-semibold">Measurement</th>
                  <th className="px-4 py-3 font-semibold">Category</th>
                  <th className="px-4 py-3 font-semibold">Range & Unit</th>
                  <th className="px-4 py-3 font-semibold">Requirement</th>
                  <th className="px-4 py-3 font-semibold">Status</th>
                  <th className="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line/60">
                {customFields.map((f) => (
                  <tr key={f.id} className="hover:bg-subtle/50 transition">
                    <td className="px-4 py-3">
                      <div className="font-semibold text-ink">{f.label}</div>
                      <div className="font-mono text-xs text-muted">{f.key}</div>
                    </td>
                    <td className="px-4 py-3">
                      <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-medium text-ink">
                        {f.product_type ?? "All Categories"}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-muted">
                      <span className="font-medium text-ink">
                        {f.min_value} – {f.max_value} {f.unit}
                      </span>
                      <span className="ml-1 text-[11px] text-muted">({f.decimals} dp)</span>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={f.is_required ? "brand" : "neutral"}>
                        {f.is_required ? "Mandatory" : "Optional"}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge active={f.is_active} />
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => update.mutate({ id: f.id, body: { is_required: !f.is_required } })}
                        >
                          {f.is_required ? "Make optional" : "Make mandatory"}
                        </Button>
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => update.mutate({ id: f.id, body: { is_active: !f.is_active } })}
                        >
                          {f.is_active ? "Deactivate" : "Activate"}
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Add Field Modal */}
      <Modal open={modalOpen} title="New Measurement Field" onClose={() => setModalOpen(false)}>
        <div className="flex flex-col gap-4">
          <FormError message={errorMessage(create.error)} />
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField
              label="Field label *"
              placeholder="e.g. Collar Meter"
              maxLength={80}
              value={form.label}
              onChange={(e) => handleLabelChange(e.target.value)}
            />
            <TextField
              label="System key *"
              placeholder="e.g. collar_meter"
              maxLength={40}
              value={form.key}
              onChange={(e) => setForm({ ...form, key: slugify(e.target.value) })}
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="text-xs font-semibold tracking-wide text-ink block mb-1.5">
                Unit of measurement *
              </label>
              <div className="flex gap-1.5 mb-1.5">
                {["m", "cm", "inches", "yds", "pcs"].map((u) => (
                  <button
                    key={u}
                    type="button"
                    onClick={() => setForm({ ...form, unit: u })}
                    className={cn(
                      "rounded-lg border px-2.5 py-1 text-xs font-medium transition cursor-pointer",
                      form.unit === u
                        ? "border-brand bg-brand/10 text-brand font-semibold"
                        : "border-line bg-surface text-muted hover:border-muted/60",
                    )}
                  >
                    {u}
                  </button>
                ))}
              </div>
              <input
                type="text"
                placeholder="Or custom unit..."
                maxLength={12}
                value={form.unit}
                onChange={(e) => setForm({ ...form, unit: e.target.value })}
                className="h-9 w-full rounded-lg border border-line bg-surface px-3 text-xs text-ink outline-none focus:border-brand"
              />
            </div>

            <SelectField
              label="Product Category"
              options={[
                { value: "", label: "All Categories" },
                ...setup.product_types.map((p) => ({ value: String(p.id), label: p.name })),
              ]}
              value={form.product_type_id}
              onChange={(e) => setForm({ ...form, product_type_id: e.target.value })}
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <TextField
              label="Min value"
              inputMode="decimal"
              value={form.min_value}
              onChange={(e) => setForm({ ...form, min_value: e.target.value })}
            />
            <TextField
              label="Max value"
              inputMode="decimal"
              value={form.max_value}
              onChange={(e) => setForm({ ...form, max_value: e.target.value })}
            />
            <SelectField
              label="Decimal precision"
              options={[
                { value: "0", label: "0 (Whole numbers)" },
                { value: "1", label: "1 (0.0)" },
                { value: "2", label: "2 (0.00)" },
                { value: "3", label: "3 (0.000)" },
              ]}
              value={form.decimals}
              onChange={(e) => setForm({ ...form, decimals: e.target.value })}
            />
          </div>

          <div className="rounded-xl border border-line bg-surface/50 p-3 flex flex-col gap-2">
            <Checkbox
              label="Mandatory field (designer must enter before submitting bulk)"
              checked={form.is_required}
              onChange={(e) => setForm({ ...form, is_required: e.target.checked })}
            />
            <Checkbox
              label="Display on printed Job Card and digital traveler"
              checked={form.show_on_job_card}
              onChange={(e) => setForm({ ...form, show_on_job_card: e.target.checked })}
            />
          </div>

          <div className="flex justify-end gap-2.5 pt-2 border-t border-line/60">
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={form.key.length < 2 || form.label.trim() === ""}
              loading={create.isPending}
              onClick={handleCreate}
            >
              Create Field
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

/* =========================================================================
   TAB 2: CHECKLIST TEMPLATES
   ========================================================================= */

function ChecklistTemplatesTab({ setup }: { setup: BulkSetup }) {
  const { notify } = useToast();
  const [stageFilter, setStageFilter] = useState("");
  const [modalOpen, setModalOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<{
    name: string;
    stage: string;
    product_type_id: string;
    items: { label: string; is_required: boolean }[];
  } | null>(null);

  const [form, setForm] = useState({
    stage: "bulk_preparation",
    product_type_id: "",
    name: "",
    lines: "",
  });

  const [inputMode, setInputMode] = useState<"paste" | "items">("paste");
  const [newItemText, setNewItemText] = useState("");
  const [newItemRequired, setNewItemRequired] = useState(true);
  const [parsedItemsList, setParsedItemsList] = useState<{ label: string; is_required: boolean }[]>([]);

  const create = useBulkSetupMutation((body: object) =>
    api.post("bulk-setup/checklist-templates", body),
  );
  const toggle = useBulkSetupMutation((input: { id: number; is_active: boolean }) =>
    api.patch(`bulk-setup/checklist-templates/${input.id}`, { is_active: input.is_active }),
  );

  const stageLabel = (s: string) => setup.stages.find((x) => x.value === s)?.label ?? s;

  const filteredTemplates = useMemo(() => {
    return setup.checklist_templates.filter((t) => {
      if (stageFilter && t.stage !== stageFilter) return false;
      return true;
    });
  }, [setup.checklist_templates, stageFilter]);

  const openNewModal = () => {
    setEditingTemplate(null);
    setForm({
      stage: "bulk_preparation",
      product_type_id: "",
      name: "Internal bulk checklist",
      lines: "",
    });
    setParsedItemsList([]);
    setInputMode("paste");
    setModalOpen(true);
  };

  const openCloneModal = (template: typeof setup.checklist_templates[number]) => {
    setEditingTemplate({
      name: template.name,
      stage: template.stage,
      product_type_id: String(template.product_type_id ?? ""),
      items: template.items,
    });
    setForm({
      stage: template.stage,
      product_type_id: String(template.product_type_id ?? ""),
      name: template.name,
      lines: template.items.map((i) => (i.is_required ? i.label : `? ${i.label}`)).join("\n"),
    });
    setParsedItemsList(template.items);
    setInputMode("items");
    setModalOpen(true);
  };

  const currentItems = useMemo(() => {
    if (inputMode === "paste") {
      return parseChecklistLines(form.lines);
    }
    return parsedItemsList;
  }, [inputMode, form.lines, parsedItemsList]);

  const handleAddItem = () => {
    if (!newItemText.trim()) return;
    const updated = [...parsedItemsList, { label: newItemText.trim(), is_required: newItemRequired }];
    setParsedItemsList(updated);
    setForm((prev) => ({
      ...prev,
      lines: updated.map((i) => (i.is_required ? i.label : `? ${i.label}`)).join("\n"),
    }));
    setNewItemText("");
  };

  const handleRemoveItem = (index: number) => {
    const updated = parsedItemsList.filter((_, i) => i !== index);
    setParsedItemsList(updated);
    setForm((prev) => ({
      ...prev,
      lines: updated.map((i) => (i.is_required ? i.label : `? ${i.label}`)).join("\n"),
    }));
  };

  const handleToggleRequired = (index: number) => {
    const updated = parsedItemsList.map((item, i) =>
      i === index ? { ...item, is_required: !item.is_required } : item,
    );
    setParsedItemsList(updated);
    setForm((prev) => ({
      ...prev,
      lines: updated.map((i) => (i.is_required ? i.label : `? ${i.label}`)).join("\n"),
    }));
  };

  const handleSaveTemplate = () => {
    if (currentItems.length === 0 || !form.name.trim()) return;
    create.mutate(
      {
        stage: form.stage,
        product_type_id: form.product_type_id ? Number(form.product_type_id) : null,
        name: form.name.trim(),
        items: currentItems,
      },
      {
        onSuccess: () => {
          notify("New checklist template version saved", "success");
          setModalOpen(false);
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-6">
      {/* Top Header & Filters */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-ink">Checklist Templates</h3>
          <p className="text-xs text-muted mt-0.5">
            QA checklist inspection gates required before cutting or external handoff. Versioning preserves historical job integrity.
          </p>
        </div>
        <Button icon={<PlusIcon size={16} />} onClick={openNewModal}>
          New Checklist Template
        </Button>
      </div>

      {/* Stage Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => setStageFilter("")}
          className={cn(
            "rounded-lg border px-3 py-1.5 text-xs font-medium transition cursor-pointer",
            stageFilter === ""
              ? "border-brand bg-brand/10 text-brand font-semibold"
              : "border-line bg-surface text-muted hover:border-muted/60",
          )}
        >
          All Stages ({setup.checklist_templates.length})
        </button>
        {setup.stages.map((st) => (
          <button
            key={st.value}
            type="button"
            onClick={() => setStageFilter(st.value)}
            className={cn(
              "rounded-lg border px-3 py-1.5 text-xs font-medium transition cursor-pointer",
              stageFilter === st.value
                ? "border-brand bg-brand/10 text-brand font-semibold"
                : "border-line bg-surface text-muted hover:border-muted/60",
            )}
          >
            {st.label} ({setup.checklist_templates.filter((t) => t.stage === st.value).length})
          </button>
        ))}
      </div>

      {/* Template Cards */}
      <div className="grid gap-4">
        {filteredTemplates.map((template) => {
          const reqCount = template.items.filter((i) => i.is_required).length;
          const optCount = template.items.length - reqCount;

          return (
            <div
              key={template.id}
              className={cn(
                "flex flex-col rounded-xl border bg-surface p-5 shadow-xs transition",
                template.is_active ? "border-line" : "border-line/40 opacity-75",
              )}
            >
              {/* Card Header */}
              <div className="flex flex-wrap items-start justify-between gap-3 border-b border-line/60 pb-4">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <h4 className="text-base font-bold text-ink">{template.name}</h4>
                    <span className="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-semibold text-brand">
                      v{template.version_no}
                    </span>
                    <Badge tone={template.stage === "bulk_preparation" ? "brand" : "warning"}>
                      {stageLabel(template.stage)}
                    </Badge>
                    <span className="rounded-md bg-subtle px-2 py-0.5 text-xs text-muted">
                      {template.product_type ?? "All Categories"}
                    </span>
                  </div>
                  <div className="mt-1 flex items-center gap-3 text-xs text-muted">
                    <span><strong>{template.items.length}</strong> checklist items</span>
                    <span>·</span>
                    <span className="text-success-strong"><strong>{reqCount}</strong> mandatory</span>
                    {optCount > 0 && (
                      <>
                        <span>·</span>
                        <span><strong>{optCount}</strong> optional</span>
                      </>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <StatusBadge active={template.is_active} />
                  <Button
                    size="sm"
                    variant="secondary"
                    icon={<CopyIcon size={14} />}
                    onClick={() => openCloneModal(template)}
                  >
                    Edit / New Version
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => toggle.mutate({ id: template.id, is_active: !template.is_active })}
                  >
                    {template.is_active ? "Deactivate" : "Activate"}
                  </Button>
                </div>
              </div>

              {/* Items inspection list */}
              <div className="mt-4">
                <div className="grid gap-2 sm:grid-cols-2">
                  {template.items.map((item, idx) => (
                    <div
                      key={item.label + idx}
                      className="flex items-center justify-between gap-2 rounded-lg border border-line/60 bg-subtle/40 px-3 py-2 text-xs"
                    >
                      <div className="flex items-center gap-2.5 min-w-0">
                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-surface border border-line font-mono text-[10px] font-semibold text-muted">
                          {idx + 1}
                        </span>
                        <span className="font-medium text-ink truncate">{item.label}</span>
                      </div>
                      <span
                        className={cn(
                          "shrink-0 text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded",
                          item.is_required
                            ? "bg-danger-soft text-danger-strong"
                            : "bg-subtle text-muted",
                        )}
                      >
                        {item.is_required ? "Must Check" : "Optional"}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* Template Modal */}
      <Modal
        open={modalOpen}
        title={editingTemplate ? `New Version: ${editingTemplate.name}` : "Create Checklist Template"}
        onClose={() => setModalOpen(false)}
      >
        <div className="flex flex-col gap-4">
          <FormError message={errorMessage(create.error)} />

          <div className="grid gap-3 sm:grid-cols-3">
            <SelectField
              label="Production Stage *"
              options={setup.stages}
              value={form.stage}
              onChange={(e) => setForm({ ...form, stage: e.target.value })}
            />
            <SelectField
              label="Category"
              options={[
                { value: "", label: "All Categories" },
                ...setup.product_types.map((p) => ({ value: String(p.id), label: p.name })),
              ]}
              value={form.product_type_id}
              onChange={(e) => setForm({ ...form, product_type_id: e.target.value })}
            />
            <TextField
              label="Template Name *"
              placeholder="e.g. Internal bulk checklist"
              maxLength={120}
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
          </div>

          {/* Mode Switcher */}
          <div className="border-t border-line/60 pt-3">
            <div className="flex items-center justify-between gap-2 mb-2">
              <span className="text-xs font-semibold text-ink">Checklist Items</span>
              <div className="flex rounded-lg border border-line p-0.5 text-xs bg-surface">
                <button
                  type="button"
                  onClick={() => setInputMode("paste")}
                  className={cn(
                    "px-2.5 py-1 rounded-md transition font-medium cursor-pointer",
                    inputMode === "paste"
                      ? "bg-brand text-white font-semibold shadow-xs"
                      : "text-muted hover:text-ink",
                  )}
                >
                  Quick Paste (Spreadsheet)
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setParsedItemsList(parseChecklistLines(form.lines));
                    setInputMode("items");
                  }}
                  className={cn(
                    "px-2.5 py-1 rounded-md transition font-medium cursor-pointer",
                    inputMode === "items"
                      ? "bg-brand text-white font-semibold shadow-xs"
                      : "text-muted hover:text-ink",
                  )}
                >
                  Interactive List
                </button>
              </div>
            </div>

            {inputMode === "paste" ? (
              <div>
                <TextAreaField
                  label="Paste items (one per line; prefix with ? for optional)"
                  placeholder={"Pattern — verify cutting pattern & template\nMaterial — verify fabric GSM\n? Photo proof of layout (optional)"}
                  rows={8}
                  value={form.lines}
                  onChange={(e) => setForm({ ...form, lines: e.target.value })}
                />
                <p className="mt-1 text-[11px] text-muted">
                  Tip: Copy directly from Excel / Google Sheets columns and paste here. Lines with leading <code className="font-mono text-ink">?</code> become optional.
                </p>
              </div>
            ) : (
              <div className="flex flex-col gap-3">
                {/* Add single item */}
                <div className="flex gap-2 items-center">
                  <input
                    type="text"
                    placeholder="Enter checklist item label..."
                    value={newItemText}
                    onChange={(e) => setNewItemText(e.target.value)}
                    onKeyDown={(e) => e.key === "Enter" && handleAddItem()}
                    className="h-9 flex-1 rounded-lg border border-line bg-surface px-3 text-xs text-ink outline-none focus:border-brand"
                  />
                  <label className="flex items-center gap-1.5 cursor-pointer text-xs select-none">
                    <input
                      type="checkbox"
                      checked={newItemRequired}
                      onChange={(e) => setNewItemRequired(e.target.checked)}
                      className="rounded border-line text-brand"
                    />
                    <span>Required</span>
                  </label>
                  <Button size="sm" onClick={handleAddItem} disabled={!newItemText.trim()}>
                    Add
                  </Button>
                </div>

                {/* Items list */}
                <div className="max-h-56 overflow-y-auto rounded-lg border border-line bg-surface/50 p-2 flex flex-col gap-1.5">
                  {parsedItemsList.length === 0 ? (
                    <p className="text-center text-xs text-muted py-4">No items yet. Add one above or switch to Quick Paste.</p>
                  ) : (
                    parsedItemsList.map((item, idx) => (
                      <div
                        key={item.label + idx}
                        className="flex items-center justify-between gap-2 rounded-md border border-line bg-surface px-2.5 py-1.5 text-xs"
                      >
                        <div className="flex items-center gap-2 truncate">
                          <span className="font-mono text-[10px] text-muted">{idx + 1}.</span>
                          <span className="font-medium text-ink truncate">{item.label}</span>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                          <button
                            type="button"
                            onClick={() => handleToggleRequired(idx)}
                            className={cn(
                              "text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded cursor-pointer transition",
                              item.is_required
                                ? "bg-danger-soft text-danger-strong hover:bg-danger/20"
                                : "bg-subtle text-muted hover:bg-subtle/80",
                            )}
                          >
                            {item.is_required ? "Must check" : "Optional"}
                          </button>
                          <button
                            type="button"
                            onClick={() => handleRemoveItem(idx)}
                            className="text-muted hover:text-danger p-0.5 transition cursor-pointer"
                          >
                            <XIcon size={14} />
                          </button>
                        </div>
                      </div>
                    ))
                  )}
                </div>
              </div>
            )}
          </div>

          {/* Footer & Counter */}
          <div className="flex items-center justify-between border-t border-line/60 pt-3">
            <span className="text-xs text-muted">
              Ready to save: <strong className="text-ink">{currentItems.length}</strong> items (
              <span className="text-success-strong">
                {currentItems.filter((i) => i.is_required).length} mandatory
              </span>
              )
            </span>
            <div className="flex items-center gap-2">
              <Button variant="secondary" onClick={() => setModalOpen(false)}>
                Cancel
              </Button>
              <Button
                disabled={!form.name.trim() || currentItems.length === 0}
                loading={create.isPending}
                onClick={handleSaveTemplate}
              >
                Save Template Version
              </Button>
            </div>
          </div>
        </div>
      </Modal>
    </div>
  );
}

/* =========================================================================
   TAB 3: EXTERNAL PRODUCERS (SUPPLIERS)
   ========================================================================= */

function SuppliersTab({ setup }: { setup: BulkSetup }) {
  const { notify } = useToast();
  const [search, setSearch] = useState("");
  const [serviceFilter, setServiceFilter] = useState("");
  const [modalOpen, setModalOpen] = useState(false);
  const [editingSupplierId, setEditingSupplierId] = useState<number | null>(null);

  const [form, setForm] = useState({
    name: "",
    phone: "",
    email: "",
    address: "",
    service_type: setup.service_types[0] ?? "full",
  });

  const create = useBulkSetupMutation((body: object) => api.post("bulk-setup/suppliers", body));
  const update = useBulkSetupMutation((input: { id: number; body: object }) =>
    api.patch(`bulk-setup/suppliers/${input.id}`, input.body),
  );

  const filteredSuppliers = useMemo(() => {
    return setup.suppliers.filter((s) => {
      if (serviceFilter && s.service_type !== serviceFilter) return false;
      if (search) {
        const q = search.toLowerCase();
        return (
          s.name.toLowerCase().includes(q) ||
          (s.phone && s.phone.toLowerCase().includes(q)) ||
          (s.email && s.email.toLowerCase().includes(q))
        );
      }
      return true;
    });
  }, [setup.suppliers, serviceFilter, search]);

  const openCreateModal = () => {
    setEditingSupplierId(null);
    setForm({
      name: "",
      phone: "",
      email: "",
      address: "",
      service_type: setup.service_types[0] ?? "full",
    });
    setModalOpen(true);
  };

  const openEditModal = (supplier: typeof setup.suppliers[number]) => {
    setEditingSupplierId(supplier.id);
    setForm({
      name: supplier.name,
      phone: supplier.phone ?? "",
      email: supplier.email ?? "",
      address: supplier.address ?? "",
      service_type: supplier.service_type,
    });
    setModalOpen(true);
  };

  const handleSave = () => {
    if (!form.name.trim()) return;
    const body = {
      name: form.name.trim(),
      service_type: form.service_type,
      phone: form.phone.trim() || null,
      email: form.email.trim() || null,
      address: form.address.trim() || null,
    };

    if (editingSupplierId) {
      update.mutate(
        { id: editingSupplierId, body },
        {
          onSuccess: () => {
            notify("Producer updated", "success");
            setModalOpen(false);
          },
        },
      );
    } else {
      create.mutate(body, {
        onSuccess: () => {
          notify("Producer added", "success");
          setModalOpen(false);
        },
      });
    }
  };

  const serviceTone = (st: string) => {
    switch (st) {
      case "printing":
        return "brand";
      case "sewing":
        return "warning";
      case "cutting":
        return "neutral";
      case "full":
        return "success";
      default:
        return "neutral";
    }
  };

  return (
    <div className="flex flex-col gap-6">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-ink">External Production Partners</h3>
          <p className="text-xs text-muted mt-0.5">
            Suppliers and sub-contractors available for external cutting, sublimation printing, and full bulk manufacturing.
          </p>
        </div>
        <Button icon={<PlusIcon size={16} />} onClick={openCreateModal}>
          Add External Producer
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <div className="relative flex-1">
          <SearchIcon size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
          <input
            type="text"
            placeholder="Search producers by name, phone, or email..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="h-9 w-full rounded-lg border border-line bg-surface pl-9 pr-3 text-xs text-ink placeholder:text-muted/60 outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
          />
        </div>
        <select
          value={serviceFilter}
          onChange={(e) => setServiceFilter(e.target.value)}
          className="h-9 rounded-lg border border-line bg-surface px-3 text-xs text-ink outline-none focus:border-brand cursor-pointer"
        >
          <option value="">All Services ({setup.suppliers.length})</option>
          {setup.service_types.map((st) => (
            <option key={st} value={st}>
              {st.charAt(0).toUpperCase() + st.slice(1)} ({setup.suppliers.filter((s) => s.service_type === st).length})
            </option>
          ))}
        </select>
      </div>

      {/* Producers Grid */}
      {filteredSuppliers.length === 0 ? (
        <div className="rounded-xl border border-line bg-surface/50 p-8 text-center">
          <BuildingIcon size={24} className="mx-auto text-muted mb-2 opacity-50" />
          <p className="text-sm font-semibold text-ink">No external producers found</p>
          <p className="text-xs text-muted mt-1 max-w-sm mx-auto">
            {search || serviceFilter
              ? "No producers match your current search criteria."
              : "Register printing shops, embroidery partners, or sewing workshops for external bulk allocation."}
          </p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {filteredSuppliers.map((supplier) => (
            <div
              key={supplier.id}
              className={cn(
                "flex flex-col justify-between rounded-xl border bg-surface p-5 shadow-xs transition hover:border-brand/40",
                supplier.is_active ? "border-line" : "border-line/40 opacity-70",
              )}
            >
              <div>
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2.5">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                      <BuildingIcon size={18} />
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-ink leading-tight">{supplier.name}</h4>
                      <Badge tone={serviceTone(supplier.service_type)} className="mt-1">
                        {supplier.service_type.toUpperCase()}
                      </Badge>
                    </div>
                  </div>
                  <StatusBadge active={supplier.is_active} />
                </div>

                {/* Contact details */}
                <div className="mt-4 flex flex-col gap-2 text-xs text-muted">
                  {supplier.phone ? (
                    <a
                      href={`tel:${supplier.phone}`}
                      className="flex items-center gap-2 hover:text-brand transition"
                    >
                      <PhoneIcon size={14} className="text-muted shrink-0" />
                      <span>{supplier.phone}</span>
                    </a>
                  ) : (
                    <div className="flex items-center gap-2 opacity-40">
                      <PhoneIcon size={14} className="shrink-0" />
                      <span>No phone recorded</span>
                    </div>
                  )}

                  {supplier.email ? (
                    <a
                      href={`mailto:${supplier.email}`}
                      className="flex items-center gap-2 hover:text-brand transition truncate"
                    >
                      <MailIcon size={14} className="text-muted shrink-0" />
                      <span className="truncate">{supplier.email}</span>
                    </a>
                  ) : (
                    <div className="flex items-center gap-2 opacity-40">
                      <MailIcon size={14} className="shrink-0" />
                      <span>No email recorded</span>
                    </div>
                  )}

                  {supplier.address && (
                    <div className="mt-1 line-clamp-2 rounded bg-subtle/50 px-2 py-1 text-[11px] text-ink/80">
                      {supplier.address}
                    </div>
                  )}
                </div>
              </div>

              {/* Card Footer Actions */}
              <div className="mt-5 flex items-center justify-between border-t border-line/60 pt-3">
                <Button
                  size="sm"
                  variant="secondary"
                  icon={<PencilIcon size={13} />}
                  onClick={() => openEditModal(supplier)}
                >
                  Edit
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => update.mutate({ id: supplier.id, body: { is_active: !supplier.is_active } })}
                >
                  {supplier.is_active ? "Deactivate" : "Activate"}
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Add / Edit Supplier Modal */}
      <Modal
        open={modalOpen}
        title={editingSupplierId ? "Edit External Producer" : "New External Producer"}
        onClose={() => setModalOpen(false)}
      >
        <div className="flex flex-col gap-4">
          <FormError message={errorMessage(create.error) ?? errorMessage(update.error)} />
          <TextField
            label="Producer / Business Name *"
            placeholder="e.g. Rapid Print Sublimation Ltd"
            maxLength={150}
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
          />

          <div className="grid gap-3 sm:grid-cols-2">
            <SelectField
              label="Service Speciality *"
              options={setup.service_types.map((st) => ({
                value: st,
                label: st.charAt(0).toUpperCase() + st.slice(1),
              }))}
              value={form.service_type}
              onChange={(e) => setForm({ ...form, service_type: e.target.value })}
            />
            <TextField
              label="Contact Phone"
              placeholder="+94 77 123 4567"
              maxLength={20}
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
            />
          </div>

          <TextField
            label="Official Email"
            type="email"
            placeholder="orders@partner.com"
            maxLength={191}
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />

          <TextAreaField
            label="Factory / Workshop Address"
            placeholder="Street address, unit number, city"
            rows={3}
            maxLength={500}
            value={form.address}
            onChange={(e) => setForm({ ...form, address: e.target.value })}
          />

          <div className="flex justify-end gap-2.5 pt-2 border-t border-line/60">
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={!form.name.trim()}
              loading={create.isPending || update.isPending}
              onClick={handleSave}
            >
              {editingSupplierId ? "Save Changes" : "Create Producer"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

/* =========================================================================
   MAIN SETUP SCREEN
   ========================================================================= */

export function BulkSetupScreen() {
  const query = useBulkSetup();
  const [activeTab, setActiveTab] = useState<SetupTab>("fields");

  return (
    <RequirePermission anyOf={["bulk.configure"]}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Bulk Production Setup"
          description="Configure factory measurement rules, quality assurance checklist gates, and verified external production partners."
        />

        {query.isPending ? (
          <LoadingState />
        ) : query.error ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : (
          (() => {
            const setup = query.data;
            const fieldsCount = setup.measurement_fields.length;
            const templatesCount = setup.checklist_templates.length;
            const suppliersCount = setup.suppliers.length;

            return (
              <div className="flex flex-col gap-6">
                {/* Segmented Tab Navigation */}
                <div className="flex border-b border-line gap-2 overflow-x-auto pb-px">
                  <button
                    type="button"
                    onClick={() => setActiveTab("fields")}
                    className={cn(
                      "flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition cursor-pointer select-none",
                      activeTab === "fields"
                        ? "border-brand text-brand font-semibold"
                        : "border-transparent text-muted hover:border-line hover:text-ink",
                    )}
                  >
                    <SlidersIcon size={16} />
                    <span>Measurement Fields</span>
                    <span className="rounded-full bg-subtle px-2 py-0.5 text-xs text-muted">
                      {fieldsCount}
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => setActiveTab("templates")}
                    className={cn(
                      "flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition cursor-pointer select-none",
                      activeTab === "templates"
                        ? "border-brand text-brand font-semibold"
                        : "border-transparent text-muted hover:border-line hover:text-ink",
                    )}
                  >
                    <CheckCircleIcon size={16} />
                    <span>Checklist Templates</span>
                    <span className="rounded-full bg-subtle px-2 py-0.5 text-xs text-muted">
                      {templatesCount}
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => setActiveTab("suppliers")}
                    className={cn(
                      "flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition cursor-pointer select-none",
                      activeTab === "suppliers"
                        ? "border-brand text-brand font-semibold"
                        : "border-transparent text-muted hover:border-line hover:text-ink",
                    )}
                  >
                    <BuildingIcon size={16} />
                    <span>External Producers</span>
                    <span className="rounded-full bg-subtle px-2 py-0.5 text-xs text-muted">
                      {suppliersCount}
                    </span>
                  </button>
                </div>

                {/* Tab Contents */}
                {activeTab === "fields" && <MeasurementFieldsTab setup={setup} />}
                {activeTab === "templates" && <ChecklistTemplatesTab setup={setup} />}
                {activeTab === "suppliers" && <SuppliersTab setup={setup} />}
              </div>
            );
          })()
        )}
      </div>
    </RequirePermission>
  );
}
