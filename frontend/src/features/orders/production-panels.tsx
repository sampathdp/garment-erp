"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
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
  PencilIcon,
  PlusIcon,
  ScissorsIcon,
  ShirtIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
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
  const [isOpen, setIsOpen] = useState(false);

  const [v, setV] = useState({
    material_id: String(details?.material?.id ?? ""),
    material_details: details?.material_details ?? "",
    design_instructions: details?.design_instructions ?? "",
    custom_notes: details?.custom_notes ?? "",
    overall_remarks: details?.overall_remarks ?? "",
  });
  const [attachment, setAttachment] = useState<File | null>(null);

  useEffect(() => {
    if (isOpen) {
      setV({
        material_id: String(details?.material?.id ?? ""),
        material_details: details?.material_details ?? "",
        design_instructions: details?.design_instructions ?? "",
        custom_notes: details?.custom_notes ?? "",
        overall_remarks: details?.overall_remarks ?? "",
      });
      setAttachment(null);
    }
  }, [isOpen, details]);

  if (!details) return null;

  const hasDetails = Boolean(details.material || details.design_instructions || details.submitted_at);

  const errors = save.error instanceof ApiError ? save.error.fieldErrors : submit.error instanceof ApiError ? submit.error.fieldErrors : {};
  const formError = [save.error, submit.error].find((e) => e instanceof ApiError && !e.isValidation) as ApiError | undefined;

  const onSave = (thenSubmit: boolean) =>
    save.mutate(
      { version: order.version, ...v, attachment },
      {
        onSuccess: (saved) => {
          setAttachment(null);
          if (thenSubmit) {
            submit.mutate(saved.version, {
              onSuccess: () => {
                notify("Job details submitted. The design task was created.", "success");
                setIsOpen(false);
              },
            });
          } else {
            notify("Job details saved successfully", "success");
            setIsOpen(false);
          }
        },
      },
    );

  const onDirectSubmit = () => {
    submit.mutate(order.version, {
      onSuccess: () => {
        notify("Job details submitted. The design task was created.", "success");
      },
    });
  };

  return (
    <div className="rounded-2xl border border-line bg-surface p-5 shadow-xs flex flex-col gap-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-line pb-4">
        <div className="flex items-start gap-3">
          <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
            <ScissorsIcon size={20} />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-sm font-bold text-ink">Job Details & Manufacturing Instructions</h3>
              {details.submitted_at ? (
                <Badge tone="success" dot>Submitted to Design</Badge>
              ) : hasDetails ? (
                <Badge tone="brand" dot>Draft Saved</Badge>
              ) : (
                <Badge tone="warning" dot>Pending Configuration</Badge>
              )}
            </div>
            <p className="text-xs text-muted mt-0.5">
              Fabric specifications, vector sketch attachments, and styling instructions for the design queue.
            </p>
          </div>
        </div>

        {editable && (
          <Button
            size="sm"
            variant={hasDetails ? "outline" : "primary"}
            icon={hasDetails ? <PencilIcon size={13} /> : <PlusIcon size={14} />}
            onClick={() => setIsOpen(true)}
            className="shrink-0 text-xs font-semibold"
          >
            {hasDetails ? "Edit Job Details" : "Configure Job Details"}
          </Button>
        )}
      </div>

      {/* Content */}
      {hasDetails ? (
        <div className="flex flex-col gap-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {/* Fabric Spec */}
            <div className="flex flex-col gap-1 rounded-xl border border-line/60 bg-subtle/30 p-3.5">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Fabric Material & Weight
              </span>
              <p className="text-sm font-bold text-ink mt-0.5">
                {details.material?.name ?? "Fabric not specified"}
              </p>
              <p className="text-xs text-muted">
                {details.material_details || "Standard GSM specification"}
              </p>
            </div>

            {/* Attachment */}
            <div className="flex flex-col gap-1 rounded-xl border border-line/60 bg-subtle/30 p-3.5">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Vector Attachment / Mockup
              </span>
              {details.attachment ? (
                <a
                  href={details.attachment.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 font-semibold text-xs text-brand hover:underline mt-1 truncate"
                >
                  <ExternalLinkIcon size={13} className="shrink-0" />
                  <span className="truncate">{details.attachment.name}</span>
                </a>
              ) : (
                <p className="text-xs text-muted italic mt-1">No reference file attached</p>
              )}
            </div>

            {/* Workflow Status */}
            <div className="flex flex-col gap-1 rounded-xl border border-line/60 bg-subtle/30 p-3.5">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Workflow Status
              </span>
              <p className="text-sm font-bold text-ink mt-0.5">
                {details.submitted_at ? "Submitted to Queue" : "Draft Instructions"}
              </p>
              <p className="text-xs text-muted">
                {details.submitted_at ? formatDateTime(details.submitted_at) : "Awaiting submission to design"}
              </p>
            </div>

            {/* Design Instructions */}
            <div className="sm:col-span-2 lg:col-span-3 flex flex-col gap-1 rounded-xl border border-line/60 bg-subtle/20 p-4">
              <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                Design Instructions
              </span>
              <p className="text-xs text-ink leading-relaxed whitespace-pre-wrap mt-1">
                {details.design_instructions || "No custom instructions specified."}
              </p>
            </div>

            {/* Custom Notes & Remarks if available */}
            {(details.custom_notes || details.overall_remarks) && (
              <div className="sm:col-span-2 lg:col-span-3 grid gap-3 sm:grid-cols-2">
                {details.custom_notes && (
                  <div className="flex flex-col gap-1 rounded-xl border border-line/60 bg-subtle/20 p-3.5">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                      Custom Notes
                    </span>
                    <p className="text-xs text-muted leading-relaxed mt-0.5">{details.custom_notes}</p>
                  </div>
                )}
                {details.overall_remarks && (
                  <div className="flex flex-col gap-1 rounded-xl border border-line/60 bg-subtle/20 p-3.5">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-muted">
                      Overall Remarks
                    </span>
                    <p className="text-xs text-muted leading-relaxed mt-0.5">{details.overall_remarks}</p>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Quick Submit Banner if drafted but not submitted yet */}
          {!details.submitted_at && order.abilities?.submit_job_details && (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand/30 bg-brand/5 p-3.5 text-xs">
              <div className="flex items-center gap-2 text-ink">
                <CheckCircleIcon size={16} className="text-brand shrink-0" />
                <span>Job instructions are drafted. Ready to dispatch this job to the design department?</span>
              </div>
              <Button
                size="sm"
                loading={submit.isPending}
                onClick={onDirectSubmit}
                className="shrink-0 text-xs font-semibold"
              >
                Send to Design Queue
              </Button>
            </div>
          )}
        </div>
      ) : (
        <div className="rounded-xl border border-dashed border-line bg-subtle/20 p-8 text-center flex flex-col items-center justify-center gap-2">
          <div className="flex size-10 items-center justify-center rounded-xl bg-subtle text-muted mb-1">
            <ScissorsIcon size={20} />
          </div>
          <h4 className="text-sm font-bold text-ink">Job Details & Fabric Instructions Not Set</h4>
          <p className="text-xs text-muted max-w-md leading-relaxed">
            Specify fabric selection, material details, vector sketch attachments, and styling notes before dispatching to the design team.
          </p>
          {editable && (
            <Button
              size="sm"
              icon={<PlusIcon size={14} />}
              onClick={() => setIsOpen(true)}
              className="mt-2 text-xs font-semibold"
            >
              Configure Job Details
            </Button>
          )}
        </div>
      )}

      {/* Modal: Configure Job Details */}
      <Modal
        open={isOpen}
        title={`Job Details & Design Instructions — Job ${order.order_no}`}
        onClose={() => setIsOpen(false)}
      >
        <form noValidate onSubmit={(e) => e.preventDefault()} className="flex flex-col gap-4">
          <FormError message={formError?.message ?? null} />

          <fieldset disabled={!editable} className="grid gap-4 sm:grid-cols-2">
            <SelectField
              label="Fabric type *"
              options={[
                { value: "", label: "Select fabric" },
                ...(materials.data ?? []).map((m) => ({ value: String(m.id), label: m.name })),
              ]}
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
                  className="text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink file:shadow-xs hover:file:bg-subtle cursor-pointer"
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
                    Current: {details.attachment.name}
                  </a>
                )}
              </div>
              {errors.attachment?.[0] && <p className="text-xs text-danger">{errors.attachment[0]}</p>}
            </div>
          </fieldset>

          <div className="flex flex-wrap items-center justify-end gap-2 pt-3 border-t border-line">
            <Button variant="secondary" onClick={() => setIsOpen(false)}>
              Cancel
            </Button>
            {editable && (
              <Button
                variant="outline"
                loading={save.isPending}
                onClick={() => onSave(false)}
              >
                Save details
              </Button>
            )}
            {order.abilities?.submit_job_details && (
              <Button
                loading={save.isPending || submit.isPending}
                onClick={() => onSave(true)}
              >
                Save and send to design
              </Button>
            )}
          </div>
        </form>
      </Modal>
    </div>
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
