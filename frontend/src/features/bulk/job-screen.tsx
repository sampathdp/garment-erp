"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { DownloadIcon, ExternalLinkIcon, FileTextIcon } from "@/components/ui/icons";
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

function Stat({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-xs">
      <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</span>
      <span className="text-sm font-bold text-ink">{children}</span>
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

  return (
    <Section title="Bulk measurements" description="Bulk meter, patch meter and the configured measurement fields. Every entry and change is recorded with who and when.">
      <div className="flex flex-col gap-4">
        <FormError message={errorMessage(mutation.error)} />
        <div className="grid gap-3 sm:grid-cols-3">
          {rows.map((r) => (
            <div key={r.key} className="flex flex-col gap-1">
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
                <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{r.label}{r.is_required ? " *" : ""}</span>
                  <span className="text-sm font-semibold text-ink">{r.value === null ? "—" : `${r.value} ${r.unit}`}</span>
                </div>
              )}
              {r.entered_by && (
                <span className="text-[11px] text-muted">
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
          <details className="rounded-lg border border-line p-3 text-xs">
            <summary className="cursor-pointer font-semibold text-ink">Measurement history ({job.measurement_history?.length})</summary>
            <ul className="mt-2 flex flex-col gap-1 text-muted">
              {job.measurement_history?.map((h, i) => (
                <li key={i}>
                  <strong className="text-ink">{h.field}</strong>: {h.old_value ?? "—"} → {h.new_value} by {h.changed_by} {formatDateTime(h.changed_at)}
                  {h.is_correction && <Badge tone="warning" className="ml-2">Correction</Badge>}
                  {h.reason ? ` · ${h.reason}` : ""}
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
      <Section title="Bulk checklist">
        <EmptyState title={job.mode === null ? "Assign the job to create its checklist" : "No checklist template is configured"} description="Checklist templates are managed in Bulk setup." />
      </Section>
    );
  }

  return (
    <Section title="Bulk checklist" description={`${checklist.template} · mandatory items (*) must be completed, and the client must confirm the final checklist.`}>
      <div className="flex flex-col gap-3">
        <FormError message={errorMessage(m.checklistItem.error)} />
        <ul className="flex flex-col divide-y divide-line rounded-xl border border-line">
          {checklist.items.map((item) => (
            <li key={item.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex flex-col gap-0.5">
                <Checkbox
                  label={`${item.label}${item.is_required ? " *" : ""}`}
                  checked={item.is_completed}
                  disabled={!editable || m.checklistItem.isPending}
                  onChange={(e) => m.checklistItem.mutate({ item: item.id, is_completed: e.target.checked })}
                />
                <span className="pl-6 text-[11px] text-muted">
                  {item.is_completed ? `Completed by ${item.completed_by} ${formatDateTime(item.completed_at)}` : "Not completed"}
                  {item.notes ? ` · ${item.notes}` : ""}
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
          <p className="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 text-xs text-emerald-800">
            Client confirmed the checklist via <strong className="capitalize">{checklist.client_confirmation.channel}</strong> — recorded by {checklist.client_confirmation.recorded_by} {formatDateTime(checklist.client_confirmation.confirmed_at)}
            {checklist.client_confirmation.comment ? ` · "${checklist.client_confirmation.comment}"` : ""}
            {checklist.client_confirmation.evidence_url && (
              <a className="ml-2 font-semibold underline" href={checklist.client_confirmation.evidence_url} target="_blank" rel="noopener noreferrer">Evidence</a>
            )}
          </p>
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
    <Section title="External production" description={`Producer: ${job.supplier?.name ?? "—"}. Record the completion here; no designer completion or WeTransfer link is needed.`}>
      <div className="flex flex-col gap-4">
        {(job.evidence ?? []).filter((e) => e.kind === "evidence").length > 0 && (
          <ul className="flex flex-col gap-1 text-xs">
            {job.evidence
              ?.filter((e) => e.kind === "evidence")
              .map((e, i) => (
                <li key={i}>
                  <a className="font-semibold text-brand hover:underline" href={e.url} target="_blank" rel="noopener noreferrer">{e.name}</a>
                  <span className="text-muted"> · {e.uploaded_by} {formatDateTime(e.uploaded_at)}{e.note ? ` · ${e.note}` : ""}</span>
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
    <Section title="Confirmed name/number list" description={job.list ? `Version ${job.list.version_no} · ${job.list.total_quantity} pcs (read-only)` : undefined}>
      <div id="list" className="flex flex-col gap-3">
        <div className="flex gap-2">
          <Button size="sm" variant="secondary" onClick={() => setOpen((o) => !o)}>{open ? "Hide list" : "Show list"}</Button>
        </div>
        {open && (list.isPending ? <LoadingState /> : list.error ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <div className="overflow-x-auto rounded-xl border border-line">
            <table className="w-full text-xs">
              <caption className="sr-only">Confirmed name/number list</caption>
              <thead className="bg-subtle text-left text-muted">
                <tr>
                  <th className="px-3 py-2">#</th>
                  <th className="px-3 py-2">Size</th>
                  <th className="px-3 py-2">Qty</th>
                  {fields.map((f) => <th key={f.key} className="px-3 py-2">{f.label}</th>)}
                  <th className="px-3 py-2">Notes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {list.data?.items.map((i) => (
                  <tr key={i.line_no}>
                    <td className="px-3 py-1.5 text-muted">{i.line_no}</td>
                    <td className="px-3 py-1.5">{i.size}</td>
                    <td className="px-3 py-1.5">{i.quantity}</td>
                    {fields.map((f) => {
                      const raw = f.key === "player_name" ? i.player_name : f.key === "number" ? i.number : i.values[f.key];
                      return <td key={f.key} className="px-3 py-1.5">{f.options.find((o) => o.code === raw)?.label ?? raw ?? ""}</td>;
                    })}
                    <td className="px-3 py-1.5 text-muted">{i.notes}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
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

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const job = query.data;
  const a = job.abilities;
  const missing = missingLines(job.completion?.missing);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Bulk — ${job.job_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href={a?.assign || job.mode === "external" || job.bulk_designer === null ? "/bulk-jobs/" : "/bulk-jobs/mine/"} className="hover:text-ink">Bulk jobs</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{job.job_no}</span>
          </div>
        }
        description={`${job.order.customer} · ${job.product_category} × ${job.order.quantity} · ${job.material ?? "No material"} · Due ${formatBusinessDate(job.order.due_date)}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Badge tone={bulkStatusTone(job.status.value)} dot>{job.status.label}</Badge>
            <Link href={`/bulk-jobs/job-card/?id=${job.id}`} className="inline-flex items-center gap-1 rounded-lg border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-subtle">
              <FileTextIcon size={14} /> Job Card
            </Link>
            {a?.assign && <Button size="sm" variant="secondary" onClick={() => setModal("assign")}>{job.mode === null ? "Assign" : "Reassign"}</Button>}
            {a?.submit_for_review && <Button size="sm" onClick={() => setModal("review-submit")}>Submit for review</Button>}
            {a?.review && <Button size="sm" onClick={() => setModal("review")}>Review bulk</Button>}
            {a?.submit_final && <Button size="sm" onClick={() => setModal("final")}>Submit final bulk</Button>}
          </div>
        }
      />

      <div className="grid gap-3 sm:grid-cols-4">
        <Stat label="Production">{job.mode === null ? <span className="text-danger">Not assigned</span> : job.mode === "internal" ? "Internal" : "External"}</Stat>
        <Stat label="Assigned to">{job.mode === "external" ? job.supplier?.name : (job.bulk_designer?.name ?? "—")}</Stat>
        <Stat label="Correction rounds">{job.review?.correction_rounds ?? 0}</Stat>
        <Stat label="Job status">{job.order.status.label}</Stat>
      </div>

      {missing.length > 0 && job.status.value !== "pending_assignment" && (
        <div className="rounded-xl border border-warning/40 bg-warning/10 p-4 text-xs text-ink">
          <p className="mb-1 font-semibold">Still needed before the bulk can be completed</p>
          <ul className="list-disc pl-5">{missing.map((line) => <li key={line}>{line}</li>)}</ul>
        </div>
      )}

      <Section title="Approved design">
        <div className="flex flex-wrap items-start gap-4 text-xs">
          {job.design_preview_url && (
            // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
            <img src={job.design_preview_url} alt={`Approved design of ${job.job_no}`} className="max-h-56 rounded-lg border border-line object-contain" />
          )}
          <div className="flex flex-col gap-2">
            <span className="text-muted">Approved design version {job.design?.approved_version_no ?? "—"}</span>
            {job.final_design_link && (
              <a className="inline-flex items-center gap-1 font-semibold text-brand hover:underline" href={job.final_design_link} target="_blank" rel="noopener noreferrer nofollow">
                Final design link <ExternalLinkIcon size={12} />
              </a>
            )}
            {job.design?.final_files.map((f) => (
              <a key={f.url} className="inline-flex items-center gap-1 font-semibold text-brand hover:underline" href={f.url}>
                <DownloadIcon size={12} /> {f.name}
              </a>
            ))}
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
