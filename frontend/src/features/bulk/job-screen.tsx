"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import {
  AlertTriangleIcon,
  BoxesIcon,
  CalendarIcon,
  CheckCircleIcon,
  ChevronLeftIcon,
  DownloadIcon,
  ExternalLinkIcon,
  FileTextIcon,
  PrinterIcon,
  ScissorsIcon,
  ShirtIcon,
  UserIcon,
} from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import { APPROVAL_CHANNELS, isAllowedDesignLink } from "@/features/designs/api";
import {
  type BulkJob,
  type BulkMeasurement,
  type BulkMode,
  bulkStatusTone,
  isAllowedTransferLink,
  measurementError,
  missingLines,
  useBulkAssignees,
  useBulkGarmentList,
  useBulkJob,
  useBulkMutations,
} from "./api";
import { JobCardView } from "./job-card";

const fileInput =
  "text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer";

function errorMessage(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const first = Object.values(error.fieldErrors)[0]?.[0];
  return first ? `${error.message} ${first}` : error.message;
}

function Stat({
  label,
  value,
  subvalue,
  icon,
}: {
  label: string;
  value: React.ReactNode;
  subvalue?: string | null;
  icon?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-4 shadow-xs transition hover:border-brand/30">
      <div className="flex items-center justify-between gap-2 mb-2">
        <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</span>
        {icon && <span className="text-muted/70">{icon}</span>}
      </div>
      <div className="flex flex-col gap-0.5">
        <span className="text-base font-bold text-ink truncate">{value}</span>
        {subvalue && <span className="text-xs text-muted truncate">{subvalue}</span>}
      </div>
    </div>
  );
}

function AssignForm({ job, onDone }: { job: BulkJob; onDone: () => void }) {
  const m = useBulkMutations(job.id);
  const assignees = useBulkAssignees(true);
  const { notify } = useToast();
  const [mode, setMode] = useState<BulkMode>(job.mode ?? "internal");
  const [designer, setDesigner] = useState(String(job.bulk_designer?.id ?? ""));
  const [supplier, setSupplier] = useState(String(job.supplier?.id ?? ""));
  const [notes, setNotes] = useState("");
  const [reason, setReason] = useState("");
  const reassign = job.mode !== null;
  const target = mode === "internal" ? designer : supplier;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(m.assign.error)} />
      <SelectField
        label="Production"
        options={[
          { value: "internal", label: "Internal — assign to a Bulk Designer" },
          { value: "external", label: "External — external bulk producer" },
        ]}
        value={mode}
        onChange={(e) => setMode(e.target.value as BulkMode)}
      />
      {mode === "internal" ? (
        <SelectField label="Bulk Designer" options={[{ value: "", label: "Choose a designer…" }, ...(assignees.data?.designers ?? []).map((d) => ({ value: String(d.id), label: d.name }))]} value={designer} onChange={(e) => setDesigner(e.target.value)} />
      ) : (
        <SelectField
          label="External producer"
          hint="External assignments can later be changed only by an authorised administrator."
          options={[{ value: "", label: "Choose a producer…" }, ...(assignees.data?.suppliers ?? []).map((s) => ({ value: String(s.id), label: s.name }))]}
          value={supplier}
          onChange={(e) => setSupplier(e.target.value)}
        />
      )}
      <TextAreaField label="Notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      {reassign && <TextField label="Reason for the reassignment *" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={!target || (reassign && reason.trim().length < 3)}
          loading={m.assign.isPending}
          onClick={() =>
            m.assign.mutate(
              { mode, bulk_designer_id: mode === "internal" ? Number(designer) : null, supplier_id: mode === "external" ? Number(supplier) : null, notes, ...(reassign ? { reason } : {}) },
              { onSuccess: () => (notify("Bulk job assigned", "success"), onDone()) },
            )
          }
        >
          {reassign ? "Reassign" : "Assign"}
        </Button>
      </div>
    </div>
  );
}

function SubmissionForm({ job, final, onDone }: { job: BulkJob; final: boolean; onDone: () => void }) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const [preview, setPreview] = useState<File | null>(null);
  const [files, setFiles] = useState<File[]>([]);
  const [link, setLink] = useState("");
  const [notes, setNotes] = useState("");
  const mutation = final ? m.submitFinal : m.submitForReview;
  const linkHint = link === "" ? undefined : final ? (isAllowedTransferLink(link) ? undefined : "Use an https WeTransfer link (wetransfer.com or we.tl).") : isAllowedDesignLink(link) ? undefined : "Use an https Google Drive / Docs link.";
  const ready = final ? files.length > 0 && link !== "" && !linkHint : (preview !== null || files.length > 0) && !linkHint;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(mutation.error)} />
      <div className="flex flex-col gap-1.5">
        <label htmlFor="bulk-preview" className="text-xs font-semibold text-ink">Layout preview image (JPG, PNG, WebP)</label>
        <input id="bulk-preview" type="file" accept="image/jpeg,image/png,image/webp" className={fileInput} onChange={(e) => setPreview(e.target.files?.[0] ?? null)} />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="bulk-files" className="text-xs font-semibold text-ink">{final ? "Final bulk file(s) (PDF, CDR) *" : "Bulk review file(s) (PDF, CDR)"}</label>
        <input id="bulk-files" type="file" multiple accept=".pdf,.cdr,application/pdf" className={fileInput} onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 5))} />
      </div>
      <TextField
        label={final ? "WeTransfer link *" : "Shared layout link (optional)"}
        type="url"
        maxLength={500}
        placeholder={final ? "https://wetransfer.com/downloads/…" : "https://drive.google.com/…"}
        value={link}
        error={linkHint}
        hint="Stored for staff only; the server never opens the link."
        onChange={(e) => setLink(e.target.value.trim())}
      />
      <TextAreaField label="Notes" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={!ready}
          loading={mutation.isPending}
          onClick={() =>
            mutation.mutate(final ? { preview, files, transfer_url: link, notes } : { preview, files, external_url: link || undefined, notes }, {
              onSuccess: () => (notify(final ? "Final bulk submitted. The task is completed." : "Bulk submitted for review", "success"), onDone()),
            })
          }
        >
          {final ? "Submit final bulk" : "Submit for review"}
        </Button>
      </div>
    </div>
  );
}

function ReviewForm({ job, onDone }: { job: BulkJob; onDone: () => void }) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const [decision, setDecision] = useState<"approve" | "correct">("approve");
  const [text, setText] = useState("");
  const error = decision === "approve" ? m.approve.error : m.requestCorrection.error;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(error)} />
      <SelectField
        label="Decision"
        options={[
          { value: "approve", label: "Approve bulk" },
          { value: "correct", label: "Request correction" },
        ]}
        value={decision}
        onChange={(e) => setDecision(e.target.value as "approve" | "correct")}
      />
      <TextAreaField label={decision === "approve" ? "Comment" : "Correction reason *"} maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} />
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          variant={decision === "approve" ? "primary" : "danger"}
          disabled={decision === "correct" && text.trim().length < 3}
          loading={m.approve.isPending || m.requestCorrection.isPending}
          onClick={() =>
            decision === "approve"
              ? m.approve.mutate(text, { onSuccess: () => (notify("Bulk approved. The designer was notified.", "success"), onDone()) })
              : m.requestCorrection.mutate(text, { onSuccess: () => (notify("Correction requested", "success"), onDone()) })
          }
        >
          {decision === "approve" ? "Approve" : "Request correction"}
        </Button>
      </div>
    </div>
  );
}

function MeasurementsSection({ job }: { job: BulkJob }) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const rows = job.measurements ?? [];
  const [draft, setDraft] = useState<Record<string, string> | null>(null);
  const [reason, setReason] = useState("");
  const editable = Boolean(job.abilities?.enter_measurements);
  const correcting = !editable && Boolean(job.abilities?.correct_measurements);
  const values = draft ?? Object.fromEntries(rows.map((r) => [r.key, r.value ?? ""]));
  const changed = Object.fromEntries(Object.entries(values).filter(([k, v]) => v.trim() !== "" && v !== (rows.find((r) => r.key === k)?.value ?? "")));
  const errors = Object.fromEntries(rows.map((r: BulkMeasurement) => [r.key, measurementError(r, values[r.key] ?? "")]));
  const invalid = Object.values(errors).some((e) => e !== null);
  const mutation = correcting ? m.correctMeasurements : m.measurements;

  const bulkVal = parseFloat(values.bulk_meter || "0") || 0;
  const patchVal = parseFloat(values.patch_meter || "0") || 0;
  const collarVal = parseFloat(values.collar_meter || "0") || 0;
  const totalMeters = bulkVal + patchVal + collarVal;
  const jobQty = job.order.quantity || 1;
  const avgYield = totalMeters > 0 ? totalMeters / jobQty : 0;

  return (
    <Section title="Bulk production measurements" description="Yardage calculations (bulk meter, patch meter) and cutting parameters. Every entry is recorded with audit history.">
      <div className="flex flex-col gap-4">
        <FormError message={errorMessage(mutation.error)} />

        {/* Live Yardage & Consumption KPI Bar (Matches Designer Manual Sheet) */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 rounded-2xl border border-brand/20 bg-brand-soft/20 p-4 shadow-xs">
          <div className="flex flex-col gap-0.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-muted">Bulk Fabric Meter</span>
            <span className="font-mono text-base font-extrabold text-ink">{bulkVal > 0 ? `${bulkVal.toFixed(2)} m` : "—"}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-muted">Patch Meter</span>
            <span className="font-mono text-base font-extrabold text-ink">{patchVal > 0 ? `${patchVal.toFixed(2)} m` : "—"}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-brand">Total Fabric Meters</span>
            <span className="font-mono text-base font-black text-brand">{totalMeters > 0 ? `${totalMeters.toFixed(2)} m` : "—"}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Average Yield / Piece</span>
            <span className="font-mono text-base font-extrabold text-emerald-600">
              {avgYield > 0 ? `${avgYield.toFixed(3)} m/pc` : "—"}
            </span>
          </div>
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
          {rows.map((r) => (
            <div key={r.key} className="flex flex-col gap-1.5 rounded-xl border border-line bg-surface p-3.5 shadow-xs">
              {editable || correcting ? (
                <TextField
                  label={`${r.label} (${r.unit})${r.is_required ? " *" : ""}`}
                  inputMode="decimal"
                  value={values[r.key] ?? ""}
                  error={errors[r.key] ?? undefined}
                  hint={`${r.min_value} – ${r.max_value} ${r.unit}, up to ${r.decimals} decimals`}
                  onChange={(e) => setDraft({ ...values, [r.key]: e.target.value })}
                />
              ) : (
                <div className="flex flex-col gap-1">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{r.label}{r.is_required ? " *" : ""}</span>
                  <span className="text-base font-bold text-ink">{r.value === null ? "—" : `${r.value} ${r.unit}`}</span>
                </div>
              )}
              {r.entered_by && (
                <span className="text-[10px] text-muted border-t border-line/60 pt-1.5 mt-1">
                  {r.updated_by ? `Changed by ${r.updated_by} ${formatDateTime(r.updated_at)}` : `Entered by ${r.entered_by} ${formatDateTime(r.entered_at)}`}
                </span>
              )}
            </div>
          ))}
        </div>
        {correcting && <TextField label="Correction reason *" hint="The bulk is completed: corrections are audited and allowed only until printing starts." maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
        {(editable || correcting) && (
          <div className="flex justify-end">
            <Button
              disabled={invalid || Object.keys(changed).length === 0 || (correcting && reason.trim().length < 3)}
              loading={mutation.isPending}
              onClick={() => {
                const opts = { onSuccess: () => (notify("Measurements saved. The Job Card is updated.", "success"), setDraft(null), setReason("")) };
                if (correcting) m.correctMeasurements.mutate({ values: changed, reason }, opts);
                else m.measurements.mutate(changed, opts);
              }}
            >
              {correcting ? "Save correction" : "Save measurements"}
            </Button>
          </div>
        )}
        {(job.measurement_history ?? []).length > 0 && (
          <details className="rounded-xl border border-line bg-subtle/20 p-3.5 text-xs">
            <summary className="cursor-pointer font-bold text-ink">Measurement history ({job.measurement_history?.length} changes)</summary>
            <ul className="mt-2.5 flex flex-col gap-1.5 text-muted">
              {job.measurement_history?.map((h, i) => (
                <li key={i} className="flex items-center gap-2">
                  <strong className="text-ink font-semibold">{h.field}:</strong>
                  <span>{h.old_value ?? "—"} → {h.new_value}</span>
                  <span className="text-muted">by {h.changed_by} ({formatDateTime(h.changed_at)})</span>
                  {h.is_correction && <Badge tone="warning">Correction</Badge>}
                  {h.reason && <span className="italic text-muted">"{h.reason}"</span>}
                </li>
              ))}
            </ul>
          </details>
        )}
      </div>
    </Section>
  );
}

function ChecklistSection({ job, onConfirm }: { job: BulkJob; onConfirm: () => void }) {
  const m = useBulkMutations(job.id);
  const checklist = job.checklist;
  const [notes, setNotes] = useState<Record<number, string>>({});
  const editable = Boolean(job.abilities?.update_checklist) && !checklist?.client_confirmation;

  if (!checklist) {
    return (
      <Section title="Bulk production checklist">
        <EmptyState title={job.mode === null ? "Assign the job to create its checklist" : "No checklist template is configured"} description="Checklist templates are managed in Bulk setup." />
      </Section>
    );
  }

  const items = checklist.items;
  const completedCount = items.filter((i) => i.is_completed).length;
  const totalCount = items.length;
  const percent = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;

  return (
    <Section title="Bulk production checklist" description={`${checklist.template} · Mandatory tasks must be checked, and client confirmation recorded.`}>
      <div className="flex flex-col gap-4">
        <FormError message={errorMessage(m.checklistItem.error)} />

        {/* Readiness Progress Bar */}
        <div className="flex flex-col gap-2 rounded-xl border border-line bg-subtle/40 p-4 shadow-xs">
          <div className="flex items-center justify-between text-xs">
            <span className="font-bold text-ink">Checklist Tasks Progress:</span>
            <span className={`font-mono font-bold ${percent === 100 ? "text-emerald-500" : "text-brand"}`}>
              {completedCount} of {totalCount} completed ({percent}%)
            </span>
          </div>
          <div className="h-2.5 w-full overflow-hidden rounded-full bg-subtle">
            <div
              className={`h-full rounded-full transition-all duration-300 ${percent === 100 ? "bg-emerald-500" : "bg-brand"}`}
              style={{ width: `${percent}%` }}
            />
          </div>
        </div>

        <ul className="flex flex-col divide-y divide-line rounded-xl border border-line bg-surface shadow-xs">
          {items.map((item) => (
            <li key={item.id} className="flex flex-col gap-2 p-3.5 sm:flex-row sm:items-center sm:justify-between hover:bg-subtle/20 transition">
              <div className="flex flex-col gap-1">
                <Checkbox
                  label={<span className="font-semibold text-ink">{item.label}{item.is_required ? " *" : ""}</span>}
                  checked={item.is_completed}
                  disabled={!editable || m.checklistItem.isPending}
                  onChange={(e) => m.checklistItem.mutate({ item: item.id, is_completed: e.target.checked })}
                />
                <span className="pl-6 text-[11px] text-muted">
                  {item.is_completed ? (
                    <span className="text-emerald-500 font-semibold">✓ Completed by {item.completed_by} on {formatDateTime(item.completed_at)}</span>
                  ) : (
                    <span className="text-amber-500 font-medium">○ Pending verification</span>
                  )}
                  {item.notes ? ` · Note: "${item.notes}"` : ""}
                </span>
              </div>
              {editable && (
                <div className="flex items-center gap-2 sm:w-80">
                  <TextField label="Notes" maxLength={500} value={notes[item.id] ?? item.notes ?? ""} onChange={(e) => setNotes({ ...notes, [item.id]: e.target.value })} />
                  <Button size="sm" variant="secondary" disabled={(notes[item.id] ?? item.notes ?? "") === (item.notes ?? "")} onClick={() => m.checklistItem.mutate({ item: item.id, notes: notes[item.id] ?? "" })}>
                    Save
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>

        {checklist.client_confirmation ? (
          <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-xs text-emerald-950 flex items-start gap-3 shadow-xs">
            <CheckCircleIcon size={20} className="text-emerald-600 shrink-0 mt-0.5" />
            <div>
              <strong className="font-bold text-ink block mb-0.5">
                Client confirmed the checklist via <span className="capitalize">{checklist.client_confirmation.channel}</span>
              </strong>
              <p className="text-muted">
                Recorded by {checklist.client_confirmation.recorded_by} on {formatDateTime(checklist.client_confirmation.confirmed_at)}
                {checklist.client_confirmation.comment ? ` · "${checklist.client_confirmation.comment}"` : ""}
              </p>
              {checklist.client_confirmation.evidence_url && (
                <a className="mt-1.5 inline-flex items-center gap-1 font-semibold text-brand underline" href={checklist.client_confirmation.evidence_url} target="_blank" rel="noopener noreferrer">
                  <span>View client confirmation evidence</span>
                  <ExternalLinkIcon size={12} />
                </a>
              )}
            </div>
          </div>
        ) : (
          job.abilities?.confirm_checklist && (
            <div className="flex justify-end">
              <Button size="sm" onClick={onConfirm}>Record client confirmation</Button>
            </div>
          )
        )}
      </div>
    </Section>
  );
}

function ConfirmChecklistForm({ job, onDone }: { job: BulkJob; onDone: () => void }) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const [channel, setChannel] = useState("whatsapp");
  const [comment, setComment] = useState("");
  const [evidence, setEvidence] = useState<File | null>(null);

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(m.confirmChecklist.error)} />
      <SelectField label="Customer channel" options={APPROVAL_CHANNELS} value={channel} onChange={(e) => setChannel(e.target.value)} />
      <TextAreaField label="Customer comment" maxLength={1000} value={comment} onChange={(e) => setComment(e.target.value)} />
      <div className="flex flex-col gap-1.5">
        <label htmlFor="checklist-evidence" className="text-xs font-semibold text-ink">Evidence (screenshot or PDF, optional)</label>
        <input id="checklist-evidence" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
      </div>
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button loading={m.confirmChecklist.isPending} onClick={() => m.confirmChecklist.mutate({ channel, comment, evidence }, { onSuccess: () => (notify("Client confirmation recorded", "success"), onDone()) })}>
          Record confirmation
        </Button>
      </div>
    </div>
  );
}

function ExternalSection({ job, onComplete }: { job: BulkJob; onComplete: () => void }) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [note, setNote] = useState("");

  return (
    <Section title="External production" description={`Producer: ${job.supplier?.name ?? "—"}. Record completion here.`}>
      <div className="flex flex-col gap-4">
        {(job.evidence ?? []).filter((e) => e.kind === "evidence").length > 0 && (
          <ul className="flex flex-col gap-1.5 text-xs">
            {job.evidence
              ?.filter((e) => e.kind === "evidence")
              .map((e, i) => (
                <li key={i} className="flex items-center gap-2">
                  <a className="font-semibold text-brand hover:underline inline-flex items-center gap-1" href={e.url} target="_blank" rel="noopener noreferrer">
                    <span>{e.name}</span>
                    <ExternalLinkIcon size={12} />
                  </a>
                  <span className="text-muted">· {e.uploaded_by} on {formatDateTime(e.uploaded_at)}{e.note ? ` · ${e.note}` : ""}</span>
                </li>
              ))}
          </ul>
        )}
        {job.abilities?.manage_external && (
          <>
            <FormError message={errorMessage(m.addEvidence.error)} />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="flex flex-col gap-1.5">
                <label htmlFor="external-evidence" className="text-xs font-semibold text-ink">Evidence (photo or PDF)</label>
                <input id="external-evidence" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              </div>
              <TextField label="Note" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
              <Button size="sm" variant="secondary" disabled={!file} loading={m.addEvidence.isPending} onClick={() => file && m.addEvidence.mutate({ file, note }, { onSuccess: () => (notify("Evidence attached", "success"), setFile(null), setNote("")) })}>
                Attach
              </Button>
            </div>
            <div className="flex justify-end">
              <Button onClick={onComplete}>Record external completion</Button>
            </div>
          </>
        )}
      </div>
    </Section>
  );
}

function ListSection({ job }: { job: BulkJob }) {
  const [open, setOpen] = useState(false);
  const list = useBulkGarmentList(job.id, open);
  const fields = list.data?.schema.fields ?? [];

  return (
    <Section title="Confirmed garment roster & sizes" description={job.list ? `Version ${job.list.version_no} · ${job.list.total_quantity} garments allocated` : undefined}>
      <div id="list" className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-5 shadow-xs">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
              <ShirtIcon size={22} />
            </div>
            <div>
              <span className="text-sm font-bold text-ink block">
                {job.list?.total_quantity ?? job.order.quantity} Garments Allocated & Invoiced
              </span>
              <span className="text-xs text-muted">
                Version {job.list?.version_no ?? 1} · Tied to Sales Invoice
              </span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={`/bulk-jobs/job-card/?id=${job.id}`}
              className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-brand/30 bg-brand/10 px-3.5 text-xs font-bold text-brand hover:bg-brand hover:text-white transition shadow-2xs"
            >
              <PrinterIcon size={14} />
              <span>Open & Print Job Card</span>
            </Link>

            <Button
              size="sm"
              variant="secondary"
              onClick={() => setOpen((o) => !o)}
            >
              {open ? "Hide roster table" : "Show roster table"}
            </Button>
          </div>
        </div>

        {open && (
          list.isPending ? (
            <LoadingState label="Loading player roster items…" />
          ) : list.error ? (
            <ErrorState error={list.error} onRetry={() => list.refetch()} />
          ) : (
            <div className="overflow-x-auto rounded-xl border border-line bg-surface shadow-xs">
              <table className="w-full text-xs text-left">
                <caption className="sr-only">Confirmed name/number list</caption>
                <thead className="bg-subtle/60 border-b border-line text-[11px] font-bold uppercase tracking-wider text-muted">
                  <tr>
                    <th className="px-3.5 py-3">#</th>
                    <th className="px-3.5 py-3">Size</th>
                    <th className="px-3.5 py-3">Qty</th>
                    <th className="px-3.5 py-3">Player Name</th>
                    <th className="px-3.5 py-3">Number</th>
                    {fields
                      .filter((f) => f.key !== "player_name" && f.key !== "number")
                      .map((f) => (
                        <th key={f.key} className="px-3.5 py-3">{f.label}</th>
                      ))}
                    <th className="px-3.5 py-3">Notes</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line/70">
                  {list.data?.items.map((i) => (
                    <tr key={i.line_no} className="hover:bg-subtle/30 transition">
                      <td className="px-3.5 py-2.5 font-mono text-muted text-xs font-semibold">
                        {i.line_no}
                      </td>
                      <td className="px-3.5 py-2.5">
                        <span className="rounded-md bg-brand/10 border border-brand/20 px-2 py-0.5 text-xs font-bold text-brand">
                          {i.size ?? "—"}
                        </span>
                      </td>
                      <td className="px-3.5 py-2.5 font-bold text-ink">{i.quantity}</td>
                      <td className="px-3.5 py-2.5 font-extrabold text-ink">
                        {i.player_name || <span className="text-muted font-normal italic">—</span>}
                      </td>
                      <td className="px-3.5 py-2.5">
                        {i.number ? (
                          <span className="font-mono text-xs font-black text-ink bg-subtle px-2 py-0.5 rounded border border-line">
                            #{i.number}
                          </span>
                        ) : (
                          <span className="text-muted italic">—</span>
                        )}
                      </td>
                      {fields
                        .filter((f) => f.key !== "player_name" && f.key !== "number")
                        .map((f) => {
                          const raw = i.values[f.key];
                          return (
                            <td key={f.key} className="px-3.5 py-2.5 text-ink font-medium">
                              {f.options.find((o) => o.code === raw)?.label ?? raw ?? "—"}
                            </td>
                          );
                        })}
                      <td className="px-3.5 py-2.5 text-muted text-xs">
                        {i.notes || "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        )}
      </div>
    </Section>
  );
}

function BulkJobDetail({ id }: { id: number }) {
  const query = useBulkJob(id);
  const m = useBulkMutations(id);
  const { notify } = useToast();
  const [modal, setModal] = useState<"assign" | "review-submit" | "final" | "review" | "confirm" | "complete" | null>(null);
  const [completionNotes, setCompletionNotes] = useState("");

  if (query.isPending) return <LoadingState label="Loading bulk job workspace…" />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const job = query.data;
  const a = job.abilities;
  const missing = missingLines(job.completion?.missing);

  return (
    <div className="flex flex-col gap-6">
      {/* Executive Command Header */}
      <div className="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6 shadow-xs">
        {/* Breadcrumb + Metadata */}
        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
          <div className="flex items-center gap-2">
            <Link
              href={a?.assign || job.mode === "external" || job.bulk_designer === null ? "/bulk-jobs/" : "/bulk-jobs/mine/"}
              className="hover:text-ink font-medium"
            >
              Bulk Production
            </Link>
            <span>/</span>
            <Link href={`/orders/view/?id=${job.order.id}`} className="hover:text-ink font-mono font-semibold text-brand">
              Order {job.job_no}
            </Link>
            <span>/</span>
            <span className="font-semibold text-ink">Production Workspace</span>
          </div>

          <div className="flex items-center gap-2">
            <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
              Branch: {job.branch}
            </span>
            <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink">
              Page: {job.page.name} ({job.page.code})
            </span>
          </div>
        </div>

        {/* Title, Badges & Action Bar */}
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-line pb-5">
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-2xl font-extrabold tracking-tight text-ink">
                Bulk Job — {job.job_no}
              </h1>

              <Badge tone={bulkStatusTone(job.status.value)} dot>
                {job.status.label}
              </Badge>

              {job.order.is_urgent && (
                <Badge tone="danger" dot>
                  ⚡ URGENT PRIORITY
                </Badge>
              )}

              <Badge tone="neutral">
                {job.mode === null ? "Unassigned" : job.mode === "internal" ? "Internal Production" : "External Production"}
              </Badge>
            </div>

            <p className="text-xs text-muted">
              Client: <strong className="text-ink font-semibold">{job.order.customer}</strong> · Category: {job.product_category} · Quantity: {job.order.quantity} pcs · Fabric: {job.material ?? "Standard Fabric"} · Islandwide Target: {formatBusinessDate(job.order.due_date)}
            </p>
          </div>

          {/* Action Buttons */}
          <div className="flex flex-wrap items-center gap-2">
            <Link
              href={`/bulk-jobs/job-card/?id=${job.id}`}
              className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-brand/30 bg-brand/10 px-3.5 text-xs font-bold text-brand shadow-xs hover:bg-brand hover:text-white transition"
            >
              <PrinterIcon size={14} />
              <span>Production Job Card</span>
            </Link>

            {a?.assign && (
              <Button size="sm" variant="secondary" onClick={() => setModal("assign")}>
                {job.mode === null ? "Assign" : "Reassign"}
              </Button>
            )}

            {a?.submit_for_review && (
              <Button size="sm" onClick={() => setModal("review-submit")}>
                Submit for review
              </Button>
            )}

            {a?.review && (
              <Button size="sm" onClick={() => setModal("review")}>
                Review bulk
              </Button>
            )}

            {a?.submit_final && (
              <Button size="sm" onClick={() => setModal("final")}>
                Submit final bulk
              </Button>
            )}
          </div>
        </div>

        {/* 4 KPI Summary Tiles */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Stat
            label="Production Assignment"
            value={job.mode === null ? <span className="text-danger">Not assigned</span> : job.mode === "internal" ? "Internal Workshop" : "External Producer"}
            subvalue={job.mode === "external" ? job.supplier?.name : (job.bulk_designer?.name ?? "No designer assigned")}
            icon={<UserIcon size={18} className="text-brand" />}
          />

          <Stat
            label="Total Order Volume"
            value={`${job.order.quantity} garments`}
            subvalue={job.product_category}
            icon={<BoxesIcon size={18} className="text-amber-500" />}
          />

          <Stat
            label="Fabric & Material"
            value={job.material ?? "Standard"}
            subvalue="Confirmed production material"
            icon={<ScissorsIcon size={18} className="text-emerald-500" />}
          />

          <Stat
            label="Delivery Target"
            value={formatBusinessDate(job.order.due_date)}
            subvalue={`Job Status: ${job.order.status.label}`}
            icon={<CalendarIcon size={18} className="text-purple-500" />}
          />
        </div>
      </div>

      {missing.length > 0 && job.status.value !== "pending_assignment" && (
        <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-950 shadow-xs flex items-start gap-3">
          <AlertTriangleIcon size={18} className="text-amber-600 shrink-0 mt-0.5" />
          <div>
            <p className="font-bold text-sm text-ink mb-1">Items still required before bulk completion:</p>
            <ul className="list-disc pl-4 space-y-1 text-ink/80">
              {missing.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          </div>
        </div>
      )}

      {/* Approved Design Showcase Card */}
      <Section title="Approved production design" description="Final vector artwork and production proofs approved for bulk cutting & sublimation.">
        <div className="flex flex-col lg:flex-row items-stretch gap-6 rounded-2xl border border-line bg-surface p-5 shadow-xs">
          {/* Left Preview Image Box */}
          <div className="flex flex-1 items-center justify-center overflow-hidden rounded-xl border border-line bg-subtle/30 p-3 min-h-[160px]">
            {job.design_preview_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={job.design_preview_url}
                alt={`Approved design of ${job.job_no}`}
                className="max-h-64 w-full object-contain rounded-lg shadow-2xs transition hover:scale-[1.01]"
              />
            ) : (
              <div className="flex flex-col items-center gap-2 py-8 text-center text-xs text-muted">
                <ShirtIcon size={28} className="text-muted/60" />
                <p>No preview graphic available</p>
              </div>
            )}
          </div>

          {/* Right Info & Actions Panel: fixed width to prevent horizontal squeezing */}
          <div className="flex flex-col justify-between gap-4 w-full lg:w-80 shrink-0 border-t lg:border-t-0 lg:border-l border-line pt-4 lg:pt-0 lg:pl-6">
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-bold text-sm text-ink">
                  Approved Design Version {job.design?.approved_version_no ?? "—"}
                </span>
                <Badge tone="success" dot>Client Approved</Badge>
              </div>
              <p className="text-xs text-muted leading-relaxed">
                This artwork proof was approved by customer and merchandiser. All cutting lines and numbers must follow this file.
              </p>
            </div>

            <div className="flex flex-col gap-2 pt-2 border-t border-line/60">
              {job.final_design_link && (
                <a
                  className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-brand/30 bg-brand/10 px-3.5 text-xs font-bold text-brand hover:bg-brand hover:text-white transition shadow-2xs"
                  href={job.final_design_link}
                  target="_blank"
                  rel="noopener noreferrer nofollow"
                >
                  <ExternalLinkIcon size={13} />
                  <span>Open Full High-Res Artwork Link</span>
                </a>
              )}

              {job.design?.final_files.map((f) => (
                <a
                  key={f.url}
                  className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 text-xs font-semibold text-ink hover:bg-subtle transition shadow-2xs truncate"
                  href={f.url}
                  title={f.name}
                >
                  <DownloadIcon size={13} className="shrink-0" />
                  <span className="truncate">{f.name}</span>
                </a>
              ))}
            </div>
          </div>
        </div>
      </Section>

      {job.mode !== null && <MeasurementsSection key={`m-${job.version}`} job={job} />}
      {job.mode !== null && <ChecklistSection job={job} onConfirm={() => setModal("confirm")} />}
      {job.mode === "external" && <ExternalSection job={job} onComplete={() => setModal("complete")} />}

      {job.mode === "internal" && (
        <Section title="Bulk versions & reviews" description="Every submission is kept; corrections are recorded against the version they concern.">
          {(job.versions ?? []).length === 0 ? (
            <EmptyState title="No bulk submitted yet" />
          ) : (
            <div className="flex flex-col gap-4">
              {job.versions?.map((v) => (
                <div key={v.id} className="rounded-xl border border-line bg-surface p-4 shadow-xs">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line/60 pb-2">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-bold text-ink">Version {v.version_no}</span>
                      {v.is_final && <Badge tone="success" dot>Final</Badge>}
                      {v.is_approved && <Badge tone="success">Approved</Badge>}
                      {v.is_current && !v.is_final && <Badge tone="brand">Current</Badge>}
                      <span className="text-[11px] text-muted">List v{v.list_version_no}</span>
                    </div>
                    <span className="text-xs text-muted">{v.submitted_by} · {formatDateTime(v.submitted_at)}</span>
                  </div>
                  {v.notes && <p className="mt-2 text-xs text-ink">{v.notes}</p>}
                  <div className="mt-3 flex flex-wrap gap-3">
                    {v.files.map((f) =>
                      f.kind === "preview" ? (
                        // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
                        <img key={f.id} src={f.url} alt={`Bulk version ${v.version_no}`} className="max-h-48 rounded-lg border border-line object-contain" />
                      ) : (
                        <a key={f.id} className="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-xs font-semibold text-brand hover:bg-subtle" href={f.url}>
                          <DownloadIcon size={14} /> {f.name}
                        </a>
                      ),
                    )}
                    {v.external_url && <a className="inline-flex items-center gap-1 text-xs font-semibold text-brand" href={v.external_url} target="_blank" rel="noopener noreferrer nofollow">Shared layout <ExternalLinkIcon size={12} /></a>}
                    {v.transfer_url && <a className="inline-flex items-center gap-1 text-xs font-semibold text-brand" href={v.transfer_url} target="_blank" rel="noopener noreferrer nofollow">WeTransfer <ExternalLinkIcon size={12} /></a>}
                  </div>
                  {v.reviews.map((r, i) => (
                    <p key={i} className="mt-2 text-xs text-muted">
                      <Badge tone={r.decision === "approved" ? "success" : "danger"} className="mr-2">{r.decision === "approved" ? "Approved" : r.is_system ? "Invalidated" : `Correction round ${r.round_no}`}</Badge>
                      {r.reviewed_by} · {formatDateTime(r.reviewed_at)}{r.reason ? ` · ${r.reason}` : ""}
                    </p>
                  ))}
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      <ListSection job={job} />

      {(job.assignment?.history ?? []).length > 0 && (
        <Section title="Assignment history">
          <ul className="flex flex-col gap-1.5 text-xs">
            {job.assignment?.history.map((h, i) => (
              <li key={i} className="text-muted">
                <Badge tone={h.status === "current" ? "brand" : "neutral"} className="mr-2">{h.status === "current" ? "Current" : "Ended"}</Badge>
                <strong className="text-ink">{h.mode === "internal" ? `Internal · ${h.bulk_designer}` : `External · ${h.supplier}`}</strong> — by {h.assigned_by} {formatDateTime(h.assigned_at)}
                {h.reason ? ` · Reason: ${h.reason}` : ""}
                {h.notes ? ` · ${h.notes}` : ""}
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Modal open={modal === "assign"} title={job.mode === null ? "Assign bulk production" : "Reassign bulk production"} onClose={() => setModal(null)}>
        {modal === "assign" && <AssignForm job={job} onDone={() => setModal(null)} />}
      </Modal>
      <Modal open={modal === "review-submit" || modal === "final"} title={modal === "final" ? "Final bulk submission" : "Submit bulk for review"} onClose={() => setModal(null)}>
        {(modal === "review-submit" || modal === "final") && <SubmissionForm job={job} final={modal === "final"} onDone={() => setModal(null)} />}
      </Modal>
      <Modal open={modal === "review"} title="Review bulk" onClose={() => setModal(null)}>
        {modal === "review" && <ReviewForm job={job} onDone={() => setModal(null)} />}
      </Modal>
      <Modal open={modal === "confirm"} title="Client confirmation of the checklist" onClose={() => setModal(null)}>
        {modal === "confirm" && <ConfirmChecklistForm job={job} onDone={() => setModal(null)} />}
      </Modal>
      <Modal open={modal === "complete"} title="Record external completion" onClose={() => setModal(null)}>
        <div className="flex flex-col gap-4">
          <FormError message={errorMessage(m.completeExternal.error)} />
          {m.completeExternal.error instanceof ApiError && Object.keys(m.completeExternal.error.fieldErrors).length > 0 && (
            <ul className="list-disc pl-5 text-xs text-danger">{Object.values(m.completeExternal.error.fieldErrors).flat().map((l) => <li key={l}>{l}</li>)}</ul>
          )}
          <TextAreaField label="Completion notes" maxLength={2000} value={completionNotes} onChange={(e) => setCompletionNotes(e.target.value)} />
          <div className="flex justify-end gap-2.5">
            <Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button>
            <Button loading={m.completeExternal.isPending} onClick={() => m.completeExternal.mutate(completionNotes, { onSuccess: () => (notify("External bulk completed", "success"), setModal(null)) })}>
              Mark completed
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

export function BulkJobScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Bulk job not found" /> : <BulkJobDetail id={id} />)}</WithIdParam>;
}

export function JobCardScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Job card not found" /> : <JobCardView id={id} />)}</WithIdParam>;
}
