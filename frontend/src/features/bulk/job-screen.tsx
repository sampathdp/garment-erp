"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, SelectField, TextAreaField } from "@/components/ui/form-controls";
import {
  AlertTriangleIcon,
  BoxesIcon,
  CalendarIcon,
  CheckCircleIcon,
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ClockIcon,
  DownloadIcon,
  ExternalLinkIcon,
  FileTextIcon,
  PrinterIcon,
  ScissorsIcon,
  SearchIcon,
  ShirtIcon,
  SparklesIcon,
  UserIcon,
  XIcon,
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
  "text-xs text-muted file:mr-2.5 file:rounded-md file:border-0 file:bg-subtle file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer";

function errorMessage(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const first = Object.values(error.fieldErrors)[0]?.[0];
  return first ? `${error.message} ${first}` : error.message;
}

function AssignForm({ job, onDone }: { job: BulkJob; onDone: () => void }) {
  const m = useBulkMutations(job.id);
  const assignees = useBulkAssignees(true, job.id);
  const { notify } = useToast();
  const [mode, setMode] = useState<BulkMode>(job.mode ?? "internal");
  const [designer, setDesigner] = useState(String(job.bulk_designer?.id ?? ""));
  const [supplier, setSupplier] = useState(String(job.supplier?.id ?? ""));
  const [notes, setNotes] = useState("");
  const [reason, setReason] = useState("");
  const reassign = job.mode !== null;
  const target = mode === "internal" ? designer : supplier;

  const freeDesigner = assignees.data?.designers?.find((d) => d.is_free);

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
        <>
          {freeDesigner && (
            <div className="flex items-center justify-between rounded-lg border border-brand/30 bg-brand/5 p-3 text-xs text-ink">
              <div className="flex items-center gap-2">
                <SparklesIcon size={16} className="text-brand shrink-0" />
                <div>
                  <span className="font-semibold text-brand">Auto-assign recommendation:</span>{" "}
                  <span>{freeDesigner.name} is currently free ({freeDesigner.active_jobs ?? 0} active jobs).</span>
                </div>
              </div>
              <button
                type="button"
                className="ml-2 font-semibold text-brand hover:underline shrink-0"
                onClick={() => setDesigner(String(freeDesigner.id))}
              >
                Use free
              </button>
            </div>
          )}
          <SelectField
            label="Bulk Designer"
            options={[
              { value: "", label: "Choose a designer…" },
              ...(assignees.data?.designers ?? []).map((d) => ({
                value: String(d.id),
                label: `${d.name}${d.active_jobs !== undefined ? ` (${d.active_jobs} active job${d.active_jobs === 1 ? "" : "s"})${d.is_free ? " · Free" : ""}` : ""}`,
              })),
            ]}
            value={designer}
            onChange={(e) => setDesigner(e.target.value)}
          />
        </>
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
        <Button
          loading={m.confirmChecklist.isPending}
          onClick={() =>
            m.confirmChecklist.mutate({ channel, comment, evidence }, {
              onSuccess: () => (notify("Checklist customer confirmation recorded", "success"), onDone()),
            })
          }
        >
          Confirm checklist
        </Button>
      </div>
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// COCKPIT PILLAR 1: Approved Artwork Proof & Download Files
// -----------------------------------------------------------------------------------------
function ArtworkCockpitCard({ job }: { job: BulkJob }) {
  return (
    <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-3.5 shadow-2xs min-h-[300px]">
      <div>
        <div className="flex items-center justify-between gap-2 border-b border-line/60 pb-2">
          <div className="flex items-center gap-1.5">
            <ShirtIcon size={15} className="text-brand shrink-0" />
            <span className="text-xs font-bold text-ink truncate">Approved Artwork</span>
            <span className="rounded bg-subtle px-1.5 py-0.2 text-[10px] font-bold text-muted font-mono">
              v{job.design?.approved_version_no ?? 1}
            </span>
          </div>
          <Badge tone="success" dot className="text-[10px] py-0.5">
            Client Approved
          </Badge>
        </div>

        {/* Thumbnail Preview Area */}
        <div className="mt-2.5 flex h-36 w-full items-center justify-center overflow-hidden rounded-lg border border-line/70 bg-subtle/30 p-2">
          {job.design_preview_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={job.design_preview_url}
              alt={`Approved design of ${job.job_no}`}
              className="max-h-full max-w-full object-contain rounded transition hover:scale-105"
            />
          ) : (
            <div className="flex flex-col items-center gap-1 text-center text-xs text-muted">
              <ShirtIcon size={24} className="text-muted/60" />
              <span className="text-[11px]">No preview graphic</span>
            </div>
          )}
        </div>
      </div>

      {/* Action / Download Links */}
      <div className="mt-2.5 flex flex-col gap-1.5 border-t border-line/60 pt-2.5">
        {job.final_design_link && (
          <a
            className="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-lg border border-brand/30 bg-brand/10 px-3 text-xs font-bold text-brand hover:bg-brand hover:text-white transition shadow-2xs"
            href={job.final_design_link}
            target="_blank"
            rel="noopener noreferrer nofollow"
          >
            <ExternalLinkIcon size={12} />
            <span className="truncate">Open Full Vector Link</span>
          </a>
        )}

        {(job.design?.final_files ?? []).slice(0, 2).map((f) => (
          <a
            key={f.url}
            className="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-semibold text-ink hover:bg-subtle transition shadow-2xs truncate"
            href={f.url}
            title={f.name}
          >
            <DownloadIcon size={12} className="shrink-0" />
            <span className="truncate">{f.name}</span>
          </a>
        ))}
      </div>
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// COCKPIT PILLAR 2: Yardage, Meters & Cutting Yield
// -----------------------------------------------------------------------------------------
function MeasurementsCockpitCard({
  job,
  onOpenHistory,
}: {
  job: BulkJob;
  onOpenHistory: () => void;
}) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const rows = job.measurements ?? [];
  const [draft, setDraft] = useState<Record<string, string> | null>(null);
  const [reason, setReason] = useState("");
  const editable = Boolean(job.abilities?.enter_measurements);
  const correcting = !editable && Boolean(job.abilities?.correct_measurements);
  const values = draft ?? Object.fromEntries(rows.map((r) => [r.key, r.value ?? ""]));
  const changed = Object.fromEntries(
    Object.entries(values).filter(([k, v]) => v.trim() !== "" && v !== (rows.find((r) => r.key === k)?.value ?? "")),
  );
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
    <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-3.5 shadow-2xs min-h-[300px]">
      <div>
        <div className="flex items-center justify-between gap-2 border-b border-line/60 pb-2">
          <div className="flex items-center gap-1.5">
            <ScissorsIcon size={15} className="text-emerald-500 shrink-0" />
            <span className="text-xs font-bold text-ink">Production Yardage</span>
          </div>

          {(job.measurement_history ?? []).length > 0 && (
            <button
              type="button"
              onClick={onOpenHistory}
              className="inline-flex items-center gap-1 text-[11px] font-semibold text-brand hover:underline cursor-pointer"
            >
              <ClockIcon size={11} />
              <span>{job.measurement_history?.length} edits</span>
            </button>
          )}
        </div>

        {/* Live Yardage Metric Strip (Directly like their physical Excel sheet) */}
        <div className="mt-2.5 grid grid-cols-4 gap-1.5 rounded-lg border border-line bg-subtle/40 p-2 text-center">
          <div className="flex flex-col">
            <span className="text-[10px] font-bold uppercase text-muted">Bulk</span>
            <span className="font-mono text-xs font-bold text-ink">
              {bulkVal > 0 ? `${bulkVal.toFixed(2)}m` : "—"}
            </span>
          </div>
          <div className="flex flex-col">
            <span className="text-[10px] font-bold uppercase text-muted">Patch</span>
            <span className="font-mono text-xs font-bold text-ink">
              {patchVal > 0 ? `${patchVal.toFixed(2)}m` : "—"}
            </span>
          </div>
          <div className="flex flex-col">
            <span className="text-[10px] font-bold uppercase text-brand">Total</span>
            <span className="font-mono text-xs font-black text-brand">
              {totalMeters > 0 ? `${totalMeters.toFixed(2)}m` : "—"}
            </span>
          </div>
          <div className="flex flex-col">
            <span className="text-[10px] font-bold uppercase text-emerald-600">Avg/Pc</span>
            <span className="font-mono text-xs font-black text-emerald-600">
              {avgYield > 0 ? `${avgYield.toFixed(3)}m` : "—"}
            </span>
          </div>
        </div>

        {/* Compact Input Fields Grid */}
        <div className="mt-2.5 grid grid-cols-2 gap-2">
          {rows.map((r) => (
            <div key={r.key} className="flex flex-col gap-0.5 rounded-lg border border-line/70 bg-subtle/20 p-2">
              <span className="text-[10px] font-bold uppercase tracking-wider text-muted truncate">
                {r.label} ({r.unit}){r.is_required ? " *" : ""}
              </span>
              {editable || correcting ? (
                <input
                  type="text"
                  inputMode="decimal"
                  className="mt-0.5 w-full rounded border border-line bg-surface px-2 py-1 font-mono text-xs font-bold text-ink focus:border-brand focus:outline-hidden"
                  value={values[r.key] ?? ""}
                  placeholder={`${r.min_value}–${r.max_value}`}
                  onChange={(e) => setDraft({ ...values, [r.key]: e.target.value })}
                />
              ) : (
                <span className="font-mono text-sm font-bold text-ink">
                  {r.value === null ? "—" : `${r.value} ${r.unit}`}
                </span>
              )}
              {errors[r.key] && <span className="text-[10px] text-danger">{errors[r.key]}</span>}
            </div>
          ))}
        </div>

        {correcting && (
          <input
            type="text"
            placeholder="Correction reason *"
            maxLength={500}
            className="mt-2 w-full rounded border border-line bg-surface px-2.5 py-1 text-xs text-ink"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        )}
      </div>

      {/* Save Action */}
      {(editable || correcting) && (
        <div className="mt-2.5 flex justify-end border-t border-line/60 pt-2">
          <Button
            size="sm"
            disabled={invalid || Object.keys(changed).length === 0 || (correcting && reason.trim().length < 3)}
            loading={mutation.isPending}
            onClick={() => {
              const opts = {
                onSuccess: () => (notify("Measurements saved. The Job Card is updated.", "success"), setDraft(null), setReason("")),
              };
              if (correcting) m.correctMeasurements.mutate({ values: changed, reason }, opts);
              else m.measurements.mutate(changed, opts);
            }}
          >
            {correcting ? "Save correction" : "Save measurements"}
          </Button>
        </div>
      )}
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// COCKPIT PILLAR 3: Production QA Checklist
// -----------------------------------------------------------------------------------------
function ChecklistCockpitCard({
  job,
  onConfirm,
}: {
  job: BulkJob;
  onConfirm: () => void;
}) {
  const m = useBulkMutations(job.id);
  const checklist = job.checklist;
  const editable = Boolean(job.abilities?.update_checklist) && !checklist?.client_confirmation;

  if (!checklist) {
    return (
      <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-3.5 shadow-2xs min-h-[300px]">
        <div className="flex items-center gap-1.5 border-b border-line/60 pb-2">
          <CheckCircleIcon size={15} className="text-brand shrink-0" />
          <span className="text-xs font-bold text-ink">QA Checklist</span>
        </div>
        <div className="flex flex-col items-center justify-center py-8 text-center text-xs text-muted">
          <CheckCircleIcon size={24} className="text-muted/60 mb-1" />
          <p className="font-semibold text-ink">{job.mode === null ? "Assign job to create checklist" : "No checklist template configured"}</p>
          <p className="text-[11px]">Templates are managed in Bulk Setup.</p>
        </div>
        <div />
      </div>
    );
  }

  const items = checklist.items;
  const completedCount = items.filter((i) => i.is_completed).length;
  const totalCount = items.length;
  const percent = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;

  return (
    <div className="flex flex-col justify-between rounded-xl border border-line bg-surface p-3.5 shadow-2xs min-h-[300px]">
      <div>
        <div className="flex items-center justify-between gap-2 border-b border-line/60 pb-2">
          <div className="flex items-center gap-1.5">
            <CheckCircleIcon size={15} className="text-brand shrink-0" />
            <span className="text-xs font-bold text-ink">QA Checklist</span>
            <span className="text-[10px] text-muted truncate">({checklist.template})</span>
          </div>

          <span className={`font-mono text-xs font-bold ${percent === 100 ? "text-emerald-500" : "text-brand"}`}>
            {completedCount}/{totalCount} ({percent}%)
          </span>
        </div>

        {/* Progress bar */}
        <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-subtle">
          <div
            className={`h-full rounded-full transition-all duration-300 ${percent === 100 ? "bg-emerald-500" : "bg-brand"}`}
            style={{ width: `${percent}%` }}
          />
        </div>

        {/* Compact Checklist Scroll Container */}
        <div className="mt-2.5 max-h-44 overflow-y-auto divide-y divide-line/40 pr-1">
          {items.map((item) => (
            <div key={item.id} className="flex items-center justify-between py-1.5 px-1 hover:bg-subtle/30 rounded text-xs">
              <Checkbox
                label={
                  <span className={`font-medium ${item.is_completed ? "text-muted line-through" : "text-ink"}`}>
                    {item.label}{item.is_required ? " *" : ""}
                  </span>
                }
                checked={item.is_completed}
                disabled={!editable || m.checklistItem.isPending}
                onChange={(e) => m.checklistItem.mutate({ item: item.id, is_completed: e.target.checked })}
              />

              {item.is_completed && (
                <span className="text-[10px] text-emerald-500 font-semibold font-mono pl-2 shrink-0">
                  ✓ {item.completed_by ? item.completed_by.split(" ")[0] : "Done"}
                </span>
              )}
            </div>
          ))}
        </div>
      </div>

      {/* Client confirmation footer */}
      <div className="mt-2.5 border-t border-line/60 pt-2">
        {checklist.client_confirmation ? (
          <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1.5 text-xs text-emerald-950 flex items-center justify-between">
            <div className="flex items-center gap-1.5">
              <CheckCircleIcon size={14} className="text-emerald-600 shrink-0" />
              <span className="font-semibold text-ink text-[11px] truncate">
                Confirmed via <span className="capitalize">{checklist.client_confirmation.channel}</span> ({checklist.client_confirmation.recorded_by})
              </span>
            </div>
            {checklist.client_confirmation.evidence_url && (
              <a className="text-[10px] text-brand underline font-semibold shrink-0" href={checklist.client_confirmation.evidence_url} target="_blank" rel="noopener noreferrer">
                Proof ↗
              </a>
            )}
          </div>
        ) : (
          job.abilities?.confirm_checklist && (
            <div className="flex justify-end">
              <Button size="sm" variant="secondary" onClick={onConfirm}>
                Record client confirmation
              </Button>
            </div>
          )
        )}
      </div>
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// CONFIRMED GARMENT ROSTER: High-Density Self-Contained Grid with Sticky Header
// -----------------------------------------------------------------------------------------
function GarmentRosterCard({ job }: { job: BulkJob }) {
  const list = useBulkGarmentList(job.id, true);
  const [search, setSearch] = useState("");
  const fields = list.data?.schema.fields ?? [];

  // Size breakdown chips computation
  const sizeBreakdown = useMemo(() => {
    return (list.data?.items ?? []).reduce<Record<string, number>>((acc, item) => {
      const s = item.size || "Unknown";
      acc[s] = (acc[s] || 0) + (item.quantity || 1);
      return acc;
    }, {});
  }, [list.data?.items]);

  // Real-time search filter
  const filteredItems = useMemo(() => {
    if (!search.trim()) return list.data?.items ?? [];
    const q = search.toLowerCase().trim();
    return (list.data?.items ?? []).filter((i) => {
      return (
        String(i.line_no).includes(q) ||
        (i.size && i.size.toLowerCase().includes(q)) ||
        (i.player_name && i.player_name.toLowerCase().includes(q)) ||
        (i.number && i.number.toLowerCase().includes(q)) ||
        (i.notes && i.notes.toLowerCase().includes(q))
      );
    });
  }, [list.data?.items, search]);

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-3.5 shadow-2xs">
      {/* Roster Header Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line/60 pb-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1.5 font-bold text-ink text-sm">
            <ShirtIcon size={16} className="text-brand shrink-0" />
            <span>Confirmed Garment Roster</span>
          </div>

          <span className="rounded-md bg-subtle px-2 py-0.5 text-xs font-semibold text-ink font-mono">
            {job.list?.total_quantity ?? job.order.quantity} Garments (v{job.list?.version_no ?? 1})
          </span>

          {/* Size Breakdown Chips */}
          <div className="flex flex-wrap items-center gap-1 pl-1">
            {Object.entries(sizeBreakdown).map(([size, count]) => (
              <span
                key={size}
                className="rounded-md border border-brand/20 bg-brand/5 px-2 py-0.5 text-[11px] font-bold text-brand"
              >
                {size}: {count}
              </span>
            ))}
          </div>
        </div>

        {/* Real-time search & Print Job Card link */}
        <div className="flex items-center gap-2">
          <div className="relative">
            <SearchIcon size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted" />
            <input
              type="text"
              placeholder="Filter player or #…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-8 w-44 sm:w-56 rounded-lg border border-line bg-subtle/50 pl-8 pr-2.5 text-xs text-ink placeholder:text-muted focus:border-brand focus:outline-hidden"
            />
            {search && (
              <button
                type="button"
                onClick={() => setSearch("")}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-ink cursor-pointer"
              >
                <XIcon size={12} />
              </button>
            )}
          </div>

          <Link
            href={`/bulk-jobs/job-card/?id=${job.id}`}
            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-brand/30 bg-brand/10 px-3 text-xs font-bold text-brand hover:bg-brand hover:text-white transition shadow-2xs shrink-0"
          >
            <PrinterIcon size={13} />
            <span className="hidden sm:inline">Job Card</span>
          </Link>
        </div>
      </div>

      {/* Scrollable Data Table Container with Sticky Header */}
      {list.isPending ? (
        <LoadingState label="Loading confirmed roster…" />
      ) : list.error ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <div className="max-h-[320px] overflow-y-auto rounded-lg border border-line/70">
          <table className="w-full text-xs text-left">
            <caption className="sr-only">Confirmed garment list</caption>
            <thead className="sticky top-0 z-10 bg-subtle border-b border-line text-[11px] font-bold uppercase tracking-wider text-muted">
              <tr>
                <th className="px-3 py-2 w-12">#</th>
                <th className="px-3 py-2 w-16">Size</th>
                <th className="px-3 py-2 w-14">Qty</th>
                <th className="px-3 py-2">Player Name</th>
                <th className="px-3 py-2 w-20">Number</th>
                {fields
                  .filter((f) => f.key !== "player_name" && f.key !== "number")
                  .map((f) => (
                    <th key={f.key} className="px-3 py-2">{f.label}</th>
                  ))}
                <th className="px-3 py-2">Notes</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line/50">
              {filteredItems.length === 0 ? (
                <tr>
                  <td colSpan={6 + fields.length} className="px-3 py-6 text-center text-muted">
                    {search ? `No players found matching "${search}"` : "No garment items in list"}
                  </td>
                </tr>
              ) : (
                filteredItems.map((i) => (
                  <tr key={i.line_no} className="hover:bg-subtle/30 transition">
                    <td className="px-3 py-2 font-mono text-muted text-xs font-semibold">
                      {i.line_no}
                    </td>
                    <td className="px-3 py-2">
                      <span className="rounded-md bg-brand/10 border border-brand/20 px-1.5 py-0.5 text-xs font-bold text-brand font-mono">
                        {i.size ?? "—"}
                      </span>
                    </td>
                    <td className="px-3 py-2 font-bold text-ink">{i.quantity}</td>
                    <td className="px-3 py-2 font-extrabold text-ink font-mono">
                      {i.player_name || <span className="text-muted font-normal italic font-sans">—</span>}
                    </td>
                    <td className="px-3 py-2">
                      {i.number ? (
                        <span className="font-mono text-xs font-black text-ink bg-subtle px-1.5 py-0.5 rounded border border-line">
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
                          <td key={f.key} className="px-3 py-2 text-ink font-medium">
                            {f.options.find((o) => o.code === raw)?.label ?? raw ?? "—"}
                          </td>
                        );
                      })}
                    <td className="px-3 py-2 text-muted text-[11px] truncate max-w-xs">
                      {i.notes || "—"}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// EXTERNAL PRODUCTION SECTION
// -----------------------------------------------------------------------------------------
function ExternalSection({ job, onComplete }: { job: BulkJob; onComplete: () => void }) {
  const m = useBulkMutations(job.id);
  const { notify } = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [note, setNote] = useState("");

  return (
    <div className="rounded-xl border border-line bg-surface p-4 shadow-2xs flex flex-col gap-3">
      <div className="flex items-center justify-between border-b border-line/60 pb-2">
        <span className="font-bold text-xs text-ink">External Production Management</span>
        <span className="text-xs text-muted">Producer: <strong>{job.supplier?.name ?? "—"}</strong></span>
      </div>

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
            <div className="flex flex-col gap-1">
              <label htmlFor="external-evidence" className="text-xs font-semibold text-ink">Evidence (photo or PDF)</label>
              <input id="external-evidence" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </div>
            <TextField label="Note" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
            <Button size="sm" variant="secondary" disabled={!file} loading={m.addEvidence.isPending} onClick={() => file && m.addEvidence.mutate({ file, note }, { onSuccess: () => (notify("Evidence attached", "success"), setFile(null), setNote("")) })}>
              Attach
            </Button>
          </div>
          <div className="flex justify-end pt-2">
            <Button onClick={onComplete}>Record external completion</Button>
          </div>
        </>
      )}
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// MAIN COCKPIT CONTAINER
// -----------------------------------------------------------------------------------------
function BulkJobDetail({ id }: { id: number }) {
  const query = useBulkJob(id);
  const m = useBulkMutations(id);
  const { notify } = useToast();
  const [modal, setModal] = useState<"assign" | "review-submit" | "final" | "review" | "confirm" | "complete" | "history" | null>(null);
  const [completionNotes, setCompletionNotes] = useState("");

  if (query.isPending) return <LoadingState label="Loading bulk job cockpit…" />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const job = query.data;
  const a = job.abilities;
  const missing = missingLines(job.completion?.missing);

  return (
    <div className="flex flex-col gap-3.5 pb-8">
      {/* ================================================================================= */}
      {/* 1. COMPACT COMMAND BAR (Consolidates Header + 4 Stat Tiles into ~56px)             */}
      {/* ================================================================================= */}
      <div className="flex flex-col gap-2.5 rounded-xl border border-line bg-surface p-3.5 shadow-2xs">
        {/* Top Command Line */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2.5">
            <Link
              href={a?.assign || job.mode === "external" || job.bulk_designer === null ? "/bulk-jobs/" : "/bulk-jobs/mine/"}
              className="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline"
            >
              <ChevronLeftIcon size={14} />
              <span>Bulk Jobs</span>
            </Link>

            <span className="text-muted text-xs">/</span>

            <h1 className="font-mono text-base font-extrabold text-ink tracking-tight">
              {job.job_no}
            </h1>

            <Badge tone={bulkStatusTone(job.status.value)} dot>
              {job.status.label}
            </Badge>

            {job.order.is_urgent && (
              <Badge tone="danger" dot>
                ⚡ Urgent
              </Badge>
            )}

            <Badge tone="neutral">
              {job.mode === null ? "Unassigned" : job.mode === "internal" ? "Internal" : "External"}
            </Badge>
          </div>

          {/* Quick Action Buttons */}
          <div className="flex flex-wrap items-center gap-2">
            <Link
              href={`/bulk-jobs/job-card/?id=${job.id}`}
              className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-brand/30 bg-brand/10 px-3 text-xs font-bold text-brand hover:bg-brand hover:text-white transition shadow-2xs"
            >
              <PrinterIcon size={13} />
              <span>Job Card</span>
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

        {/* Bottom Inline Metadata Strip */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-line/50 pt-2 text-xs text-muted">
          <div>
            Client: <strong className="text-ink font-semibold">{job.order.customer}</strong>
            {job.page.name && <span className="text-muted ml-1">({job.page.name})</span>}
          </div>
          <span className="text-line">•</span>
          <div>
            Category: <strong className="text-ink font-semibold">{job.product_category}</strong>
          </div>
          <span className="text-line">•</span>
          <div>
            Volume: <strong className="text-ink font-semibold">{job.order.quantity} pcs</strong>
          </div>
          <span className="text-line">•</span>
          <div>
            Fabric: <strong className="text-ink font-semibold">{job.material ?? "Standard"}</strong>
          </div>
          <span className="text-line">•</span>
          <div>
            Target: <strong className="text-ink font-semibold">{formatBusinessDate(job.order.due_date)}</strong>
          </div>
          <span className="text-line">•</span>
          <div>
            Assigned:{" "}
            <strong className="text-ink font-semibold">
              {job.mode === "external" ? (job.supplier?.name ?? "External") : (job.bulk_designer?.name ?? "Unassigned")}
            </strong>
          </div>
        </div>
      </div>

      {/* Slim Missing Requirements Alert Banner */}
      {missing.length > 0 && job.status.value !== "pending_assignment" && (
        <div className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3.5 py-2 text-xs text-amber-950 shadow-2xs">
          <AlertTriangleIcon size={15} className="text-amber-600 shrink-0" />
          <span className="font-bold text-ink">Pending bulk completion:</span>
          <span className="text-ink/80 truncate">{missing.join(" · ")}</span>
        </div>
      )}

      {/* ================================================================================= */}
      {/* 2. THREE-PILLAR PRODUCTION COCKPIT GRID (Zero scrolling between 3 core tools)     */}
      {/* ================================================================================= */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-3.5 items-stretch">
        <div className="lg:col-span-3">
          <ArtworkCockpitCard job={job} />
        </div>

        <div className="lg:col-span-4">
          <MeasurementsCockpitCard job={job} onOpenHistory={() => setModal("history")} />
        </div>

        <div className="lg:col-span-5">
          <ChecklistCockpitCard job={job} onConfirm={() => setModal("confirm")} />
        </div>
      </div>

      {/* External Section if mode is external */}
      {job.mode === "external" && (
        <ExternalSection job={job} onComplete={() => setModal("complete")} />
      )}

      {/* ================================================================================= */}
      {/* 3. CONFIRMED GARMENT ROSTER (Full Width, Self-Contained Grid with Sticky Header)  */}
      {/* ================================================================================= */}
      <GarmentRosterCard job={job} />

      {/* ================================================================================= */}
      {/* 4. COLLAPSIBLE BOTTOM SHELF (Secondary Information)                               */}
      {/* ================================================================================= */}
      <div className="flex flex-col gap-2">
        {job.mode === "internal" && (
          <details className="group rounded-xl border border-line bg-surface p-3 shadow-2xs">
            <summary className="flex cursor-pointer items-center justify-between text-xs font-bold text-ink hover:text-brand">
              <div className="flex items-center gap-2">
                <FileTextIcon size={14} className="text-brand" />
                <span>Bulk Submissions & Version History ({job.versions?.length ?? 0} versions)</span>
              </div>
              <ChevronDownIcon size={14} className="transition-transform group-open:rotate-180 text-muted" />
            </summary>
            <div className="mt-3 pt-3 border-t border-line/60">
              {(job.versions ?? []).length === 0 ? (
                <EmptyState title="No bulk files submitted yet" />
              ) : (
                <div className="flex flex-col gap-3">
                  {job.versions?.map((v) => (
                    <div key={v.id} className="rounded-lg border border-line bg-subtle/20 p-3">
                      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line/60 pb-2">
                        <div className="flex items-center gap-2">
                          <span className="text-xs font-bold text-ink">Version {v.version_no}</span>
                          {v.is_final && <Badge tone="success" dot>Final</Badge>}
                          {v.is_approved && <Badge tone="success">Approved</Badge>}
                          {v.is_current && !v.is_final && <Badge tone="brand">Current</Badge>}
                          <span className="text-[11px] text-muted">List v{v.list_version_no}</span>
                        </div>
                        <span className="text-xs text-muted">{v.submitted_by} · {formatDateTime(v.submitted_at)}</span>
                      </div>
                      {v.notes && <p className="mt-2 text-xs text-ink">{v.notes}</p>}
                      <div className="mt-2 flex flex-wrap gap-2">
                        {v.files.map((f) =>
                          f.kind === "preview" ? (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img key={f.id} src={f.url} alt={`Bulk version ${v.version_no}`} className="max-h-32 rounded border border-line object-contain" />
                          ) : (
                            <a key={f.id} className="inline-flex items-center gap-1.5 rounded border border-line px-2.5 py-1 text-xs font-semibold text-brand hover:bg-subtle" href={f.url}>
                              <DownloadIcon size={12} /> {f.name}
                            </a>
                          ),
                        )}
                        {v.external_url && <a className="inline-flex items-center gap-1 text-xs font-semibold text-brand" href={v.external_url} target="_blank" rel="noopener noreferrer nofollow">Shared layout <ExternalLinkIcon size={11} /></a>}
                        {v.transfer_url && <a className="inline-flex items-center gap-1 text-xs font-semibold text-brand" href={v.transfer_url} target="_blank" rel="noopener noreferrer nofollow">WeTransfer <ExternalLinkIcon size={11} /></a>}
                      </div>
                      {v.reviews.map((r, i) => (
                        <p key={i} className="mt-2 text-xs text-muted">
                          <Badge tone={r.decision === "approved" ? "success" : "danger"} className="mr-2 text-[10px] py-0">{r.decision === "approved" ? "Approved" : r.is_system ? "Invalidated" : `Correction round ${r.round_no}`}</Badge>
                          {r.reviewed_by} · {formatDateTime(r.reviewed_at)}{r.reason ? ` · ${r.reason}` : ""}
                        </p>
                      ))}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </details>
        )}

        {(job.assignment?.history ?? []).length > 0 && (
          <details className="group rounded-xl border border-line bg-surface p-3 shadow-2xs">
            <summary className="flex cursor-pointer items-center justify-between text-xs font-bold text-ink hover:text-brand">
              <div className="flex items-center gap-2">
                <ClockIcon size={14} className="text-muted" />
                <span>Assignment & Reassignment Audit Trail ({job.assignment?.history?.length ?? 0} entries)</span>
              </div>
              <ChevronDownIcon size={14} className="transition-transform group-open:rotate-180 text-muted" />
            </summary>
            <div className="mt-2.5 pt-2.5 border-t border-line/60">
              <ul className="flex flex-col gap-1.5 text-xs">
                {job.assignment?.history.map((h, i) => (
                  <li key={i} className="text-muted">
                    <Badge tone={h.status === "current" ? "brand" : "neutral"} className="mr-2 text-[10px] py-0">{h.status === "current" ? "Current" : "Ended"}</Badge>
                    <strong className="text-ink">{h.mode === "internal" ? `Internal · ${h.bulk_designer}` : `External · ${h.supplier}`}</strong> — by {h.assigned_by} {formatDateTime(h.assigned_at)}
                    {h.reason ? ` · Reason: ${h.reason}` : ""}
                    {h.notes ? ` · ${h.notes}` : ""}
                  </li>
                ))}
              </ul>
            </div>
          </details>
        )}
      </div>

      {/* Modals */}
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

      {/* Modal for Measurement Change History */}
      <Modal open={modal === "history"} title="Yardage & Measurement Change History" onClose={() => setModal(null)}>
        <div className="flex flex-col gap-3">
          <ul className="flex flex-col divide-y divide-line/60 text-xs">
            {job.measurement_history?.map((h, i) => (
              <li key={i} className="flex flex-col gap-1 py-2.5">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <strong className="text-ink font-semibold">{h.field}:</strong>
                    <span className="font-mono">{h.old_value ?? "—"} → {h.new_value}</span>
                  </div>
                  {h.is_correction && <Badge tone="warning">Correction</Badge>}
                </div>
                <span className="text-[11px] text-muted">
                  Changed by {h.changed_by} ({formatDateTime(h.changed_at)})
                </span>
                {h.reason && <p className="italic text-muted text-[11px] bg-subtle/50 p-1.5 rounded">“{h.reason}”</p>}
              </li>
            ))}
          </ul>
          <div className="flex justify-end pt-2">
            <Button variant="secondary" onClick={() => setModal(null)}>Close</Button>
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
