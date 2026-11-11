"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { FormError, PageHeader, Section, SelectField, StatusBadge, TextAreaField } from "@/components/ui/form-controls";
import { RequirePermission } from "@/components/ui/record-page";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { api, ApiError } from "@/lib/api-client";
import { parseChecklistLines } from "@/features/bulk/setup-screen";

interface CuttingSetup {
  product_types: { id: number; name: string }[];
  stages: { value: string; label: string }[];
  checklist_templates: { id: number; stage: string; name: string; version_no: number; is_active: boolean; product_type: string | null; items: { label: string; is_required: boolean }[] }[];
}

const KEY = ["cutting-setup"] as const;
const message = (e: unknown) => (e instanceof ApiError ? (Object.values(e.fieldErrors)[0]?.[0] ?? e.message) : null);

/** Cutting checklist and quality check templates (cutting.configure). Saving creates a new version. */
export function CuttingSetupScreen() {
  const query = useQuery({ queryKey: KEY, queryFn: ({ signal }) => api.get<{ data: CuttingSetup }>("cutting-setup", undefined, signal).then((r) => r.data) });
  const queryClient = useQueryClient();
  const { notify } = useToast();
  const [form, setForm] = useState({ stage: "cutting", product_type_id: "", name: "", lines: "" });
  const refresh = () => void queryClient.invalidateQueries({ queryKey: KEY });
  const create = useMutation({ mutationFn: (body: object) => api.post("cutting-setup/checklist-templates", body), onSuccess: refresh });
  const toggle = useMutation({ mutationFn: (input: { id: number; is_active: boolean }) => api.patch(`cutting-setup/checklist-templates/${input.id}`, { is_active: input.is_active }), onSuccess: refresh });
  const items = parseChecklistLines(form.lines);

  return (
    <RequirePermission anyOf={["cutting.configure"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Cutting setup" description="Cutting checklist tasks and quality checks. Mandatory quality checks must pass before cutting can be completed." />
        {query.isPending ? <LoadingState /> : query.error ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
          <Section title="Templates" description="Saving a template creates a new version and deactivates the previous one. Jobs keep the checklist they started with.">
            <FormError message={message(create.error) ?? message(toggle.error)} />
            <div className="flex flex-col gap-3">
              {query.data.checklist_templates.map((t) => (
                <div key={t.id} className="rounded-xl border border-line p-3 text-xs">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-semibold text-ink">{t.name} v{t.version_no} · {query.data.stages.find((s) => s.value === t.stage)?.label ?? t.stage} · {t.product_type ?? "All categories"}</span>
                    <div className="flex items-center gap-2">
                      <StatusBadge active={t.is_active} />
                      <Button size="sm" variant="secondary" onClick={() => toggle.mutate({ id: t.id, is_active: !t.is_active })}>{t.is_active ? "Deactivate" : "Activate"}</Button>
                    </div>
                  </div>
                  <ul className="mt-2 list-disc pl-5 text-muted">{t.items.map((i) => <li key={i.label}>{i.label}{i.is_required ? " *" : " (optional)"}</li>)}</ul>
                </div>
              ))}
            </div>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
              <SelectField label="Type" options={query.data.stages} value={form.stage} onChange={(e) => setForm({ ...form, stage: e.target.value })} />
              <SelectField label="Category" options={[{ value: "", label: "All categories" }, ...query.data.product_types.map((p) => ({ value: String(p.id), label: p.name }))]} value={form.product_type_id} onChange={(e) => setForm({ ...form, product_type_id: e.target.value })} />
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
                    { onSuccess: () => (notify("Template saved", "success"), setForm({ ...form, lines: "" })) },
                  )
                }
              >
                Save template version
              </Button>
            </div>
          </Section>
        )}
      </div>
    </RequirePermission>
  );
}
