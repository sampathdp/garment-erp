"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Badge, FormError, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { TextField } from "@/components/ui/field";
import {
  BoxesIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  CreditCardIcon,
  ExternalLinkIcon,
  PaletteIcon,
  ShirtIcon,
} from "@/components/ui/icons";
import { useToast } from "@/components/ui/toast";
import { useMaterials, useOrderInvoices } from "@/features/setup/api";
import { ApiError } from "@/lib/api-client";
import { formatDateTime, formatMoney } from "@/lib/format";
import { type Order, useJobDetailsMutations } from "./api";

const ATTACHMENT_TYPES = "image/jpeg,image/png,image/webp,application/pdf";

/** Additional order information. The API only returns it from payment approval onwards. */
export function JobDetailsPanel({ order }: { order: Order }) {
  const details = order.job_details;
  const materials = useMaterials();
  const { save, submit } = useJobDetailsMutations(order.id);
  const { notify } = useToast();
  const editable = order.abilities?.manage_job_details ?? false;
  const [v, setV] = useState({
    material_id: String(details?.material?.id ?? ""),
    material_details: details?.material_details ?? "",
    design_instructions: details?.design_instructions ?? "",
    custom_notes: details?.custom_notes ?? "",
    overall_remarks: details?.overall_remarks ?? "",
  });
  const [attachment, setAttachment] = useState<File | null>(null);
  if (!details) return null;

  const errors = save.error instanceof ApiError ? save.error.fieldErrors : submit.error instanceof ApiError ? submit.error.fieldErrors : {};
  const formError = [save.error, submit.error].find((e) => e instanceof ApiError && !e.isValidation) as ApiError | undefined;

  const onSave = (thenSubmit: boolean) =>
    save.mutate(
      { version: order.version, ...v, attachment },
      {
        onSuccess: (saved) => {
          setAttachment(null);
          if (thenSubmit) submit.mutate(saved.version, { onSuccess: () => notify("Job details submitted. The design task was created.", "success") });
          else notify("Job details saved", "success");
        },
      },
    );

  return (
    <Section title="Job details" description="Fabric, material and design instructions for the designer. Added after the advance payment is approved.">
      <FormError message={formError?.message ?? null} />
      <fieldset disabled={!editable} className="grid gap-4 sm:grid-cols-2">
        <SelectField
          label="Fabric type *"
          options={[{ value: "", label: "Select fabric" }, ...(materials.data ?? []).map((m) => ({ value: String(m.id), label: m.name }))]}
          value={v.material_id}
          error={errors.material_id?.[0]}
          onChange={(e) => setV({ ...v, material_id: e.target.value })}
        />
        <TextField
          label="Material details"
          maxLength={1000}
          value={v.material_details}
          placeholder="e.g. 180 GSM dry-fit cotton blend"
          error={errors.material_details?.[0]}
          onChange={(e) => setV({ ...v, material_details: e.target.value })}
        />
        <div className="sm:col-span-2">
          <TextAreaField
            label="Design instructions *"
            maxLength={5000}
            rows={5}
            placeholder="Specify collar styling, sleeve piping, crest placement, vector Pantone colors, etc."
            value={v.design_instructions}
            error={errors.design_instructions?.[0]}
            onChange={(e) => setV({ ...v, design_instructions: e.target.value })}
          />
        </div>
        <TextAreaField
          label="Custom notes"
          maxLength={2000}
          placeholder="Internal notes or specific customer requests"
          value={v.custom_notes}
          error={errors.custom_notes?.[0]}
          onChange={(e) => setV({ ...v, custom_notes: e.target.value })}
        />
        <TextAreaField
          label="Overall remarks"
          maxLength={2000}
          placeholder="Production remarks or packaging instructions"
          value={v.overall_remarks}
          error={errors.overall_remarks?.[0]}
          onChange={(e) => setV({ ...v, overall_remarks: e.target.value })}
        />
        <div className="sm:col-span-2 flex flex-col gap-2 rounded-xl border border-dashed border-line bg-subtle/30 p-4">
          <label htmlFor="design-attachment" className="text-xs font-semibold uppercase tracking-wider text-muted">
            Design instruction attachment (Vector sketch, reference PDF or mockup)
          </label>
          <div className="flex flex-wrap items-center gap-3">
            <input
              id="design-attachment"
              type="file"
              accept={ATTACHMENT_TYPES}
              className="text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink file:shadow-xs hover:file:bg-subtle"
              onChange={(e) => setAttachment(e.target.files?.[0] ?? null)}
            />
            {details.attachment && (
              <a
                className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-brand shadow-xs hover:bg-subtle"
                href={details.attachment.url}
                target="_blank"
                rel="noopener noreferrer"
              >
                <ExternalLinkIcon size={13} />
                Open {details.attachment.name}
              </a>
            )}
          </div>
          {errors.attachment?.[0] && <p className="text-xs text-danger">{errors.attachment[0]}</p>}
        </div>
      </fieldset>
      {details.submitted_at && <p className="mt-3 text-xs text-muted">Submitted {formatDateTime(details.submitted_at)}.</p>}
      {editable && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button variant="secondary" loading={save.isPending} onClick={() => onSave(false)}>
            Save details
          </Button>
          {order.abilities?.submit_job_details && (
            <Button loading={save.isPending || submit.isPending} onClick={() => onSave(true)}>
              Save and send to design
            </Button>
          )}
        </div>
      )}
    </Section>
  );
}

export function ProductionPanel({ order }: { order: Order }) {
  const invoices = useOrderInvoices(order.id, order.status.value !== "draft" && order.job_details !== undefined);
  if (order.job_details === undefined) return null;

  return (
    <Section title="Design, list and invoice" description="Track the artwork progress, roster specifications, and official sales invoices.">
      <div className="grid gap-4 md:grid-cols-3">
        {/* Card 1: Design Phase */}
        <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-4 shadow-xs transition-shadow hover:shadow-sm">
          <div className="flex flex-col gap-2.5">
            <div className="flex items-center justify-between gap-2">
              <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
                <PaletteIcon size={14} className="text-brand" />
                Artwork & Design
              </span>
              {order.design_task && (
                <Badge tone="brand">{order.design_task.status.label}</Badge>
              )}
            </div>
            {order.design_task ? (
              <div className="flex flex-col gap-1 text-sm">
                <span className="text-xs text-muted">
                  Designer: <strong className="text-ink font-medium">{order.design_task.designer?.name ?? "Not assigned"}</strong>
                </span>
              </div>
            ) : (
              <p className="text-xs text-muted leading-relaxed">
                Task created once job instructions are finalized and submitted to the design queue.
              </p>
            )}
          </div>
          {order.design_task && (
            <div className="mt-4 pt-3 border-t border-line/60">
              <Link
                className="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline"
                href={`/designs/view/?id=${order.design_task.id}`}
              >
                Open design workspace <ChevronRightIcon size={13} />
              </Link>
            </div>
          )}
        </div>

        {/* Card 2: Garment Roster */}
        <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-4 shadow-xs transition-shadow hover:shadow-sm">
          <div className="flex flex-col gap-2.5">
            <div className="flex items-center justify-between gap-2">
              <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
                <ShirtIcon size={14} className="text-brand" />
                Roster & Sizing
              </span>
            </div>
            <p className="text-xs text-muted leading-relaxed">
              Maintain player names, sleeve numbers, size distributions, and custom badges for cutting.
            </p>
          </div>
          <div className="mt-4 pt-3 border-t border-line/60">
            <Link
              className="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline"
              href={`/orders/garment-list/?id=${order.id}`}
            >
              Configure garment list <ChevronRightIcon size={13} />
            </Link>
          </div>
        </div>

        {/* Card 3: Invoices */}
        <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-4 shadow-xs transition-shadow hover:shadow-sm">
          <div className="flex flex-col gap-2.5">
            <div className="flex items-center justify-between gap-2">
              <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
                <CreditCardIcon size={14} className="text-brand" />
                Billing Invoices
              </span>
              {(invoices.data ?? []).length > 0 && (
                <Badge tone="neutral">{(invoices.data ?? []).length} generated</Badge>
              )}
            </div>
            {(invoices.data ?? []).length === 0 ? (
              <p className="text-xs text-muted leading-relaxed">
                Official invoices are automatically generated when the garment list is confirmed.
              </p>
            ) : (
              <div className="flex flex-col gap-1.5">
                {(invoices.data ?? []).map((i) => (
                  <Link
                    key={i.id}
                    className="flex items-center justify-between rounded-lg border border-line/70 bg-subtle/30 px-2.5 py-1.5 text-xs font-medium text-ink hover:border-brand/40 hover:bg-subtle"
                    href={`/invoices/view/?id=${i.id}`}
                  >
                    <span className="font-mono text-brand font-semibold">{i.invoice_no}</span>
                    <span className="text-muted">
                      {formatMoney(i.total_minor, i.currency)} {i.status === "void" ? "(void)" : ""}
                    </span>
                  </Link>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      {order.bulk && (
        <div className="mt-6 rounded-xl border border-line bg-surface p-4 shadow-xs">
          <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
            <div className="flex items-center gap-2">
              <BoxesIcon size={16} className="text-brand" />
              <p className="text-sm font-semibold text-ink">Bulk Production Readiness Checklist</p>
            </div>
            {order.bulk.job && (
              <Link href={`/bulk-jobs/view/?id=${order.bulk.job.id}`} className="text-xs font-semibold text-brand hover:underline">
                Open bulk job ({order.bulk.job.status.replaceAll("_", " ")}) →
              </Link>
            )}
          </div>
          <div className="grid gap-2 sm:grid-cols-2">
            {order.bulk.checklist.map((c) => (
              <div
                key={c.key}
                className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium ${
                  c.met
                    ? "border-emerald-200 bg-emerald-50/50 text-emerald-800"
                    : "border-line bg-subtle/40 text-muted"
                }`}
              >
                {c.met ? (
                  <CheckCircleIcon size={14} className="text-emerald-600 shrink-0" />
                ) : (
                  <div className="h-3.5 w-3.5 rounded-full border border-muted/50 shrink-0" />
                )}
                <span>{c.label}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </Section>
  );
}
