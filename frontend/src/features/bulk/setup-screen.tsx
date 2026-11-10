"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, StatusBadge, TextAreaField } from "@/components/ui/form-controls";
import { RequirePermission } from "@/components/ui/record-page";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { api, ApiError } from "@/lib/api-client";
import { type BulkSetup, useBulkSetup, useBulkSetupMutation } from "./api";

const message = (e: unknown) => (e instanceof ApiError ? (Object.values(e.fieldErrors)[0]?.[0] ?? e.message) : null);

/** One checklist item per line; a line starting with "?" is optional. Exported for tests. */
export function parseChecklistLines(text: string): { label: string; is_required: boolean }[] {
  return text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter((l) => l !== "" && l !== "?")
    .map((l) => (l.startsWith("?") ? { label: l.slice(1).trim(), is_required: false } : { label: l, is_required: true }));
}

function Fields({ setup }: { setup: BulkSetup }) {
  const { notify } = useToast();
  const [form, setForm] = useState({ key: "", label: "", unit: "m", product_type_id: "", min_value: "0", max_value: "1000", decimals: "2", is_required: false });
  const create = useBulkSetupMutation((body: object) => api.post("bulk-setup/measurement-fields", body));
  const update = useBulkSetupMutation((input: { id: number; body: object }) => api.patch(`bulk-setup/measurement-fields/${input.id}`, input.body));

  return (
    <Section title="Measurement fields" description="Bulk meter and patch meter are always required. Add fields such as collar measurements per product category.">
      <FormError message={message(create.error) ?? message(update.error)} />
      <div className="overflow-x-auto rounded-xl border border-line">
        <table className="w-full text-xs">
          <caption className="sr-only">Measurement fields</caption>
          <thead className="bg-subtle text-left text-muted">
            <tr><th className="px-3 py-2">Field</th><th className="px-3 py-2">Category</th><th className="px-3 py-2">Range</th><th className="px-3 py-2">Required</th><th className="px-3 py-2">Status</th><th className="px-3 py-2" /></tr>
          </thead>
          <tbody className="divide-y divide-line">
            {setup.measurement_fields.map((f) => (
              <tr key={f.id}>
                <td className="px-3 py-2 font-medium text-ink">{f.label} <span className="font-mono text-muted">{f.key}</span>{f.is_system && <Badge className="ml-2">System</Badge>}</td>
                <td className="px-3 py-2">{f.product_type ?? "All"}</td>
                <td className="px-3 py-2">{f.min_value}–{f.max_value} {f.unit} · {f.decimals} dp</td>
                <td className="px-3 py-2">{f.is_required ? "Yes" : "No"}</td>
                <td className="px-3 py-2"><StatusBadge active={f.is_active} /></td>
                <td className="px-3 py-2 text-right">
                  {!f.is_system && (
                    <div className="flex justify-end gap-2">
                      <Button size="sm" variant="secondary" onClick={() => update.mutate({ id: f.id, body: { is_required: !f.is_required } })}>{f.is_required ? "Make optional" : "Make required"}</Button>
                      <Button size="sm" variant="secondary" onClick={() => update.mutate({ id: f.id, body: { is_active: !f.is_active } })}>{f.is_active ? "Deactivate" : "Activate"}</Button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-4">
        <TextField label="Key" placeholder="collar_width" maxLength={40} value={form.key} onChange={(e) => setForm({ ...form, key: e.target.value.toLowerCase() })} />
        <TextField label="Label" maxLength={80} value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} />
        <TextField label="Unit" maxLength={12} value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} />
        <SelectField label="Category" options={[{ value: "", label: "All categories" }, ...setup.product_types.map((p) => ({ value: String(p.id), label: p.name }))]} value={form.product_type_id} onChange={(e) => setForm({ ...form, product_type_id: e.target.value })} />
        <TextField label="Minimum" inputMode="decimal" value={form.min_value} onChange={(e) => setForm({ ...form, min_value: e.target.value })} />
        <TextField label="Maximum" inputMode="decimal" value={form.max_value} onChange={(e) => setForm({ ...form, max_value: e.target.value })} />
        <SelectField label="Decimals" options={["0", "1", "2", "3"].map((d) => ({ value: d, label: d }))} value={form.decimals} onChange={(e) => setForm({ ...form, decimals: e.target.value })} />
        <div className="flex items-end pb-2"><Checkbox label="Required" checked={form.is_required} onChange={(e) => setForm({ ...form, is_required: e.target.checked })} /></div>
      </div>
      <div className="mt-3 flex justify-end">
        <Button
          disabled={form.key.length < 2 || form.label === ""}
          loading={create.isPending}
          onClick={() =>
            create.mutate(
              { ...form, product_type_id: form.product_type_id ? Number(form.product_type_id) : null, min_value: Number(form.min_value), max_value: Number(form.max_value), decimals: Number(form.decimals) },
              { onSuccess: () => (notify("Measurement field added", "success"), setForm({ ...form, key: "", label: "" })) },
            )
          }
        >
          Add field
        </Button>
      </div>
    </Section>
  );
}

function Templates({ setup }: { setup: BulkSetup }) {
  const { notify } = useToast();
  const [form, setForm] = useState({ stage: "bulk_preparation", product_type_id: "", name: "", lines: "" });
  const create = useBulkSetupMutation((body: object) => api.post("bulk-setup/checklist-templates", body));
  const toggle = useBulkSetupMutation((input: { id: number; is_active: boolean }) => api.patch(`bulk-setup/checklist-templates/${input.id}`, { is_active: input.is_active }));
  const items = parseChecklistLines(form.lines);
  const stageLabel = (s: string) => setup.stages.find((x) => x.value === s)?.label ?? s;

  return (
    <Section title="Checklist templates" description="Saving a template creates a new version and deactivates the previous one. Jobs keep the checklist they started with.">
      <FormError message={message(create.error) ?? message(toggle.error)} />
      <div className="flex flex-col gap-3">
        {setup.checklist_templates.map((t) => (
          <div key={t.id} className="rounded-xl border border-line p-3 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-semibold text-ink">{t.name} v{t.version_no} · {stageLabel(t.stage)} · {t.product_type ?? "All categories"}</span>
              <div className="flex items-center gap-2">
                <StatusBadge active={t.is_active} />
                <Button size="sm" variant="secondary" onClick={() => toggle.mutate({ id: t.id, is_active: !t.is_active })}>{t.is_active ? "Deactivate" : "Activate"}</Button>
              </div>
            </div>
            <ul className="mt-2 list-disc pl-5 text-muted">
              {t.items.map((i) => <li key={i.label}>{i.label}{i.is_required ? " *" : " (optional)"}</li>)}
            </ul>
          </div>
        ))}
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <SelectField label="Stage" options={setup.stages} value={form.stage} onChange={(e) => setForm({ ...form, stage: e.target.value })} />
        <SelectField label="Category" options={[{ value: "", label: "All categories" }, ...setup.product_types.map((p) => ({ value: String(p.id), label: p.name }))]} value={form.product_type_id} onChange={(e) => setForm({ ...form, product_type_id: e.target.value })} />
        <TextField label="Template name" maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </div>
      <div className="mt-3">
        <TextAreaField label="Items (one per line; start a line with ? for an optional item)" rows={6} value={form.lines} onChange={(e) => setForm({ ...form, lines: e.target.value })} />
      </div>
      <div className="mt-3 flex justify-end">
        <Button
          disabled={form.name === "" || items.length === 0}
          loading={create.isPending}
          onClick={() =>
            create.mutate(
              { stage: form.stage, product_type_id: form.product_type_id ? Number(form.product_type_id) : null, name: form.name, items },
              { onSuccess: () => (notify("Checklist template saved", "success"), setForm({ ...form, lines: "" })) },
            )
          }
        >
          Save template version
        </Button>
      </div>
    </Section>
  );
}

function Suppliers({ setup }: { setup: BulkSetup }) {
  const { notify } = useToast();
  const [form, setForm] = useState({ name: "", phone: "", email: "", service_type: setup.service_types[0] ?? "full" });
  const create = useBulkSetupMutation((body: object) => api.post("bulk-setup/suppliers", body));
  const update = useBulkSetupMutation((input: { id: number; is_active: boolean }) => api.patch(`bulk-setup/suppliers/${input.id}`, { is_active: input.is_active }));

  return (
    <Section title="External producers" description="Suppliers available for external bulk assignment.">
      <FormError message={message(create.error) ?? message(update.error)} />
      <ul className="flex flex-col divide-y divide-line rounded-xl border border-line text-xs">
        {setup.suppliers.map((s) => (
          <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
            <span><strong className="text-ink">{s.name}</strong> <span className="text-muted">· {s.service_type}{s.phone ? ` · ${s.phone}` : ""}{s.email ? ` · ${s.email}` : ""}</span></span>
            <div className="flex items-center gap-2">
              <StatusBadge active={s.is_active} />
              <Button size="sm" variant="secondary" onClick={() => update.mutate({ id: s.id, is_active: !s.is_active })}>{s.is_active ? "Deactivate" : "Activate"}</Button>
            </div>
          </li>
        ))}
      </ul>
      <div className="mt-4 grid gap-3 sm:grid-cols-4">
        <TextField label="Name" maxLength={150} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <TextField label="Phone" maxLength={20} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
        <TextField label="Email" type="email" maxLength={191} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
        <SelectField label="Service" options={setup.service_types.map((t) => ({ value: t, label: t }))} value={form.service_type} onChange={(e) => setForm({ ...form, service_type: e.target.value })} />
      </div>
      <div className="mt-3 flex justify-end">
        <Button
          disabled={form.name.trim() === ""}
          loading={create.isPending}
          onClick={() =>
            create.mutate(
              { name: form.name, service_type: form.service_type, phone: form.phone || null, email: form.email || null },
              { onSuccess: () => (notify("Producer added", "success"), setForm({ ...form, name: "", phone: "", email: "" })) },
            )
          }
        >
          Add producer
        </Button>
      </div>
    </Section>
  );
}

export function BulkSetupScreen() {
  const query = useBulkSetup();
  return (
    <RequirePermission anyOf={["bulk.configure"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Bulk setup" description="Measurement fields, checklist templates and external producers used by bulk production." />
        {query.isPending ? <LoadingState /> : query.error ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
          <>
            <Fields setup={query.data} />
            <Templates setup={query.data} />
            <Suppliers setup={query.data} />
          </>
        )}
      </div>
    </RequirePermission>
  );
}
