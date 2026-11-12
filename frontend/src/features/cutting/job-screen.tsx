"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import { APPROVAL_CHANNELS } from "@/features/designs/api";
import { apiErrorMessage, IssueForm } from "@/features/issues/issue-form";
import { issueStatusTone, type IssueStatus } from "@/features/issues/api";
import {
  BLOCKER_LABELS,
  type CuttingChecklistItem,
  type CuttingJob,
  type QualityResult,
  cutQuantityError,
  cutTotals,
  cuttingStatusTone,
  useCutters,
  useCuttingJob,
  useCuttingJobCard,
  useCuttingMutations,
  useCuttingNameList,
} from "./api";

const fileInput =
  "text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer";

type ModalKind = "assign" | "confirm" | "complete" | "issue";

function Stat({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-xs">
      <span className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</span>
      <span className="text-sm font-bold text-ink">{children}</span>
    </div>
  );
}

function Value({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-line/60 py-2">
      <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-ink">{children}</dd>
    </div>
  );
}

function AssignForm({ job, onDone }: { job: CuttingJob; onDone: () => void }) {
  const m = useCuttingMutations(job.id);
  const cutters = useCutters(job.id, true);
  const { notify } = useToast();
  const [cutter, setCutter] = useState(job.cutter ? String(job.cutter.id) : "");
  const [date, setDate] = useState(job.expected_completion_date ?? "");
  const [notes, setNotes] = useState("");
  const [reason, setReason] = useState("");
  const reassigning = job.cutter !== null;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={apiErrorMessage(m.assign.error)} />
      <SelectField label="Cutter *" options={[{ value: "", label: "Choose a cutter…" }, ...(cutters.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))]} value={cutter} onChange={(e) => setCutter(e.target.value)} />
      <TextField label="Expected completion *" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      <TextAreaField label="Notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      {reassigning && <TextAreaField label="Reason for the change *" maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />}
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={!cutter || !date || (reassigning && reason.trim().length < 3)}
          loading={m.assign.isPending}
          onClick={() => m.assign.mutate({ cutter_id: Number(cutter), expected_completion_date: date, notes, reason }, { onSuccess: () => (notify("Cutter assigned and notified.", "success"), onDone()) })}
        >
          {reassigning ? "Reassign" : "Assign"}
        </Button>
      </div>
    </div>
  );
}

function QuantitiesSection({ job }: { job: CuttingJob }) {
  const m = useCuttingMutations(job.id);
  const { notify } = useToast();
  const rows = job.quantities ?? [];
  const [draft, setDraft] = useState<Record<number, string>>(() => Object.fromEntries(rows.map((r) => [r.id, String(r.cut)])));
  const editable = job.abilities?.record_work === true;
  const errors = Object.fromEntries(rows.map((r) => [r.id, cutQuantityError(draft[r.id] ?? "", r.expected)]));
  const invalid = Object.values(errors).some((e) => e !== null);
  const totals = cutTotals(rows.map((r) => ({ expected: r.expected, cut: errors[r.id] ? r.cut : Number(draft[r.id]) })));

  return (
    <Section title="Quantities" description="Expected quantities come from the confirmed list. Missing = expected − cut. Defective pieces are reported as issues.">
      <div className="flex flex-col gap-3">
        <FormError message={apiErrorMessage(m.quantities.error)} />
        <div className="overflow-x-auto rounded-xl border border-line">
          <table className="w-full text-xs">
            <caption className="sr-only">Cut quantities by size</caption>
            <thead className="bg-subtle text-left text-muted">
              <tr>
                <th className="px-3 py-2">Size</th>
                <th className="px-3 py-2">Expected</th>
                <th className="px-3 py-2">Cut</th>
                <th className="px-3 py-2">Missing</th>
                <th className="px-3 py-2">Last update</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-1.5 font-semibold text-ink">{r.size}</td>
                  <td className="px-3 py-1.5">{r.expected}</td>
                  <td className="px-3 py-1.5">
                    {editable ? (
                      <div className="flex flex-col gap-0.5">
                        <input
                          aria-label={`Cut quantity for size ${r.size}`}
                          className="w-20 rounded-md border border-line bg-surface px-2 py-1 text-xs"
                          inputMode="numeric"
                          value={draft[r.id] ?? ""}
                          onChange={(e) => setDraft((d) => ({ ...d, [r.id]: e.target.value }))}
                        />
                        {errors[r.id] && <span className="text-[11px] text-danger">{errors[r.id]}</span>}
                      </div>
                    ) : (
                      r.cut
                    )}
                  </td>
                  <td className={`px-3 py-1.5 ${r.missing > 0 ? "font-semibold text-danger" : "text-muted"}`}>{r.missing}</td>
                  <td className="px-3 py-1.5 text-muted">{r.updated_by ? `${r.updated_by} · ${formatDateTime(r.updated_at)}` : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
          <span>
            Total {totals.cut} / {totals.expected} cut · <strong className={totals.missing > 0 ? "text-danger" : "text-ink"}>{totals.missing} missing</strong> · {job.totals.defective} defective reported · {job.totals.good} good
          </span>
          {editable && (
            <Button
              size="sm"
              disabled={invalid}
              loading={m.quantities.isPending}
              onClick={() => m.quantities.mutate(rows.map((r) => ({ id: r.id, cut: Number(draft[r.id]) })), { onSuccess: () => notify("Quantities saved", "success") })}
            >
              Save quantities
            </Button>
          )}
        </div>
      </div>
    </Section>
  );
}

function ChecklistRow({ job, item }: { job: CuttingJob; item: CuttingChecklistItem }) {
  const m = useCuttingMutations(job.id);
  const cutters = useCutters(job.id, job.abilities?.record_work === true);
  const [notes, setNotes] = useState(item.notes ?? "");
  const locked = job.checklist?.client_confirmation !== null;
  const editable = job.abilities?.record_work === true && !locked;

  return (
    <li className="flex flex-col gap-2 rounded-lg border border-line p-3 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <input
          type="checkbox"
          aria-label={item.label}
          className="h-4 w-4"
          checked={item.is_completed}
          disabled={!editable || m.checklistItem.isPending}
          onChange={(e) => m.checklistItem.mutate({ item: item.id, is_completed: e.target.checked })}
        />
        <span className="font-semibold text-ink">{item.label}</span>
        {item.is_required && <Badge tone="neutral">Mandatory</Badge>}
        {item.completed_by && <span className="text-muted">· {item.completed_by} · {formatDateTime(item.completed_at)}</span>}
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <span className="text-muted">Responsible: {item.responsible ?? "—"}</span>
        {editable && (
          <select
            aria-label={`Responsible user for ${item.label}`}
            className="rounded-md border border-line bg-surface px-2 py-1 text-xs"
            value={item.responsible_user_id ?? ""}
            onChange={(e) => m.checklistItem.mutate({ item: item.id, responsible_user_id: e.target.value ? Number(e.target.value) : null })}
          >
            <option value="">Assigned cutter</option>
            {(cutters.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        )}
        {editable ? (
          <span className="flex items-center gap-2">
            <input aria-label={`Notes for ${item.label}`} className="w-56 rounded-md border border-line bg-surface px-2 py-1 text-xs" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />
            {notes !== (item.notes ?? "") && <Button size="sm" variant="secondary" onClick={() => m.checklistItem.mutate({ item: item.id, notes: notes.trim() || null })}>Save note</Button>}
          </span>
        ) : (
          item.notes && <span className="text-muted">Notes: {item.notes}</span>
        )}
      </div>
    </li>
  );
}

function QualityRow({ job, item }: { job: CuttingJob; item: CuttingChecklistItem }) {
  const m = useCuttingMutations(job.id);
  const [result, setResult] = useState<QualityResult | "">(item.result ?? "");
  const [notes, setNotes] = useState(item.notes ?? "");
  const editable = job.abilities?.record_quality_check === true;
  const needsNote = result === "fail" || result === "na";
  const tone = item.result === "pass" ? "success" : item.result === "fail" ? "danger" : item.result === "na" ? "warning" : "neutral";

  return (
    <li className="flex flex-col gap-2 rounded-lg border border-line p-3 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold text-ink">{item.label}</span>
        {item.is_required && <Badge tone="neutral">Mandatory — must pass</Badge>}
        <Badge tone={tone} dot>{item.result === "pass" ? "Passed" : item.result === "fail" ? "Failed" : item.result === "na" ? "N/A" : "Not checked"}</Badge>
        {item.completed_by && <span className="text-muted">· {item.completed_by} · {formatDateTime(item.completed_at)}</span>}
      </div>
      {item.notes && !editable && <span className="text-muted">Notes: {item.notes}</span>}
      {editable && (
        <div className="flex flex-wrap items-center gap-2">
          <select aria-label={`Result for ${item.label}`} className="rounded-md border border-line bg-surface px-2 py-1 text-xs" value={result} onChange={(e) => setResult(e.target.value as QualityResult | "")}>
            <option value="">Choose…</option>
            <option value="pass">Pass</option>
            <option value="fail">Fail</option>
            <option value="na">Not applicable</option>
          </select>
          <input aria-label={`Notes for ${item.label}`} placeholder={needsNote ? "Note required" : "Note"} className="w-56 rounded-md border border-line bg-surface px-2 py-1 text-xs" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />
          <Button
            size="sm"
            variant="secondary"
            disabled={!result || (needsNote && notes.trim().length < 3) || (result === item.result && notes === (item.notes ?? ""))}
            loading={m.qualityCheck.isPending}
            onClick={() => result && m.qualityCheck.mutate({ item: item.id, result, notes: notes.trim() })}
          >
            Record
          </Button>
        </div>
      )}
    </li>
  );
}

function ConfirmForm({ job, onDone }: { job: CuttingJob; onDone: () => void }) {
  const m = useCuttingMutations(job.id);
  const { notify } = useToast();
  const [channel, setChannel] = useState("whatsapp");
  const [comment, setComment] = useState("");
  const [evidence, setEvidence] = useState<File | null>(null);

  return (
    <div className="flex flex-col gap-4">
      <p className="text-xs text-muted">Record that the customer confirmed the final cutting checklist. The checklist is locked afterwards.</p>
      <FormError message={apiErrorMessage(m.confirm.error)} />
      <SelectField label="Channel *" options={APPROVAL_CHANNELS} value={channel} onChange={(e) => setChannel(e.target.value)} />
      <TextAreaField label="Customer comment" maxLength={1000} value={comment} onChange={(e) => setComment(e.target.value)} />
      <label className="flex flex-col gap-1.5 text-xs font-semibold text-ink">
        Evidence (optional, image or PDF)
        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
      </label>
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button loading={m.confirm.isPending} onClick={() => m.confirm.mutate({ channel, comment, evidence }, { onSuccess: () => (notify("Client confirmation recorded", "success"), onDone()) })}>
          Record confirmation
        </Button>
      </div>
    </div>
  );
}

function CompleteForm({ job, onDone }: { job: CuttingJob; onDone: () => void }) {
  const m = useCuttingMutations(job.id);
  const { notify } = useToast();
  const [notes, setNotes] = useState("");

  return (
    <div className="flex flex-col gap-4">
      <p className="text-xs text-muted">Completing cutting records you as the responsible person and moves the job on to sewing.</p>
      <FormError message={apiErrorMessage(m.complete.error)} />
      <TextAreaField label="Completion notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button loading={m.complete.isPending} onClick={() => m.complete.mutate(notes, { onSuccess: () => (notify("Cutting completed", "success"), onDone()) })}>Complete cutting</Button>
      </div>
    </div>
  );
}

function DocumentsSection({ job }: { job: CuttingJob }) {
  const [card, setCard] = useState(false);
  const [list, setList] = useState(false);
  const jobCard = useCuttingJobCard(job.id, card);
  const nameList = useCuttingNameList(job.id, list);
  const fields = nameList.data?.schema.fields ?? [];

  return (
    <Section title="Job Card and confirmed list" description={`List version ${job.list.version_no} · ${job.list.total_quantity} pcs (read-only)`}>
      <div className="flex flex-col gap-4">
        <div id="job-card" className="flex flex-col gap-3">
          <Button size="sm" variant="secondary" className="self-start" onClick={() => setCard((o) => !o)}>{card ? "Hide Job Card" : "View Job Card"}</Button>
          {card && (jobCard.isPending ? <LoadingState /> : jobCard.error ? <ErrorState error={jobCard.error} onRetry={() => jobCard.refetch()} /> : (
            <dl className="grid gap-x-6 sm:grid-cols-2">
              <Value label="Job No.">{jobCard.data.job_no}</Value>
              <Value label="Customer">{jobCard.data.customer}</Value>
              <Value label="Category">{jobCard.data.product.category}</Value>
              <Value label="Quantity">{jobCard.data.quantity}</Value>
              <Value label="Material">{jobCard.data.material ?? "—"}{jobCard.data.material_details ? ` · ${jobCard.data.material_details}` : ""}</Value>
              <Value label="Due date">{formatBusinessDate(jobCard.data.due_date)}</Value>
              {jobCard.data.list && <Value label="List">v{jobCard.data.list.version_no} · {jobCard.data.list.by_size.map((s) => `${s.size}: ${s.quantity}`).join(", ")}</Value>}
            </dl>
          ))}
        </div>
        <div id="name-list" className="flex flex-col gap-3">
          <Button size="sm" variant="secondary" className="self-start" onClick={() => setList((o) => !o)}>{list ? "Hide list" : "View list"}</Button>
          {list && (nameList.isPending ? <LoadingState /> : nameList.error ? <ErrorState error={nameList.error} onRetry={() => nameList.refetch()} /> : (
            <div className="overflow-x-auto rounded-xl border border-line">
              <table className="w-full text-xs">
                <caption className="sr-only">Confirmed name/number list</caption>
                <thead className="bg-subtle text-left text-muted">
                  <tr>
                    <th className="px-3 py-2">#</th>
                    <th className="px-3 py-2">Size</th>
                    <th className="px-3 py-2">Qty</th>
                    {fields.map((f) => <th key={f.key} className="px-3 py-2">{f.label}</th>)}
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {nameList.data.items.map((i) => (
                    <tr key={i.line_no}>
                      <td className="px-3 py-1.5 text-muted">{i.line_no}</td>
                      <td className="px-3 py-1.5">{i.size}</td>
                      <td className="px-3 py-1.5">{i.quantity}</td>
                      {fields.map((f) => {
                        const raw = f.key === "player_name" ? i.player_name : f.key === "number" ? i.number : i.values[f.key];
                        return <td key={f.key} className="px-3 py-1.5">{f.options.find((o) => o.code === raw)?.label ?? raw ?? ""}</td>;
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))}
        </div>
      </div>
    </Section>
  );
}

function CuttingJobDetail({ id }: { id: number }) {
  const query = useCuttingJob(id);
  const [modal, setModal] = useState<ModalKind | null>(null);
  const { notify } = useToast();
  const mutations = useCuttingMutations(id);

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const job = query.data;
  const a = job.abilities;
  const close = () => setModal(null);
  const blockers = Object.entries(job.completion?.blockers ?? {});
  const checklistDone = (job.checklist?.items ?? []).every((i) => !i.is_required || i.is_completed);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Cutting — ${job.job_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/cutting/" className="hover:text-ink">Cutting</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{job.job_no}</span>
          </div>
        }
        description={`${job.order.customer} · ${job.page.code} · ${job.product_category} × ${job.quantity} · ${job.material ?? "No material"} · Due ${formatBusinessDate(job.order.due_date)}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Badge tone={cuttingStatusTone(job.status.value)} dot>{job.status.label}</Badge>
            {a?.assign && <Button size="sm" variant={job.cutter ? "secondary" : "primary"} onClick={() => setModal("assign")}>{job.cutter ? "Reassign" : "Assign cutter"}</Button>}
            {a?.report_issue && <Button size="sm" variant="secondary" onClick={() => setModal("issue")}>Report issue</Button>}
            {a?.complete && <Button size="sm" disabled={blockers.length > 0} onClick={() => setModal("complete")}>Complete cutting</Button>}
          </div>
        }
      />

      <div className="grid gap-3 sm:grid-cols-4">
        <Stat label="Cutter">{job.cutter?.name ?? "Unassigned"}</Stat>
        <Stat label="Expected completion">{job.expected_completion_date ? formatBusinessDate(job.expected_completion_date) : "—"}</Stat>
        <Stat label="Cut / expected">{job.totals.cut} / {job.totals.expected}</Stat>
        <Stat label="Job status">{job.order.status.label}</Stat>
      </div>

      {job.status.value !== "completed" && blockers.length > 0 && (
        <Section title="Before cutting can be completed">
          <ul className="flex flex-col gap-2 text-xs">
            {blockers.map(([key, list]) => (
              <li key={key}>
                <strong className="text-ink">{BLOCKER_LABELS[key] ?? key}</strong>
                <ul className="ml-4 list-disc text-muted">{(list ?? []).map((b) => <li key={b}>{b}</li>)}</ul>
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Section title="Cutting information">
        <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
          <div className="flex flex-col gap-2">
            {job.design_preview_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
              <img src={job.design_preview_url} alt={`Approved design of ${job.job_no}`} className="w-full rounded-lg border border-line object-contain" />
            ) : (
              <div className="flex h-40 items-center justify-center rounded-lg border border-dashed border-line text-xs text-muted">No design preview</div>
            )}
            <span className="text-[11px] text-muted">Approved design version {job.design_version_no}</span>
          </div>
          <dl className="grid gap-x-6 sm:grid-cols-2">
            <Value label="Material">{job.material ?? "—"}</Value>
            <Value label="Product category">{job.product_category}</Value>
            <Value label="Quantity">{job.quantity}</Value>
            <Value label="Printed quantity">{job.printing.printed_quantity ?? "—"}</Value>
            <Value label="Printing completed">{formatDateTime(job.printing.completed_at)}</Value>
            <Value label="Printing company">{job.printing.printing_company ?? "—"}</Value>
            <Value label="Gate pass">{job.printing.gate_passes.map((g) => `${g.gate_pass_no} (${formatBusinessDate(g.gate_pass_date)})`).join(", ") || "—"}</Value>
            <Value label="Assigned">{job.assignment?.assigned_by ? `${job.assignment.assigned_by} · ${formatDateTime(job.assigned_at)}` : "—"}</Value>
          </dl>
        </div>
      </Section>

      <DocumentsSection job={job} />
      {(job.quantities ?? []).length > 0 && <QuantitiesSection key={job.version} job={job} />}

      <Section title="Cutting checklist" description="Mandatory tasks must be completed and confirmed by the client before cutting can be completed.">
        {!job.checklist ? (
          <EmptyState title={job.cutter ? "No cutting checklist is configured" : "Assign a cutter to start the checklist"} />
        ) : (
          <div className="flex flex-col gap-3">
            <ul className="flex flex-col gap-2">{job.checklist.items.map((i) => <ChecklistRow key={`${i.id}-${job.version}`} job={job} item={i} />)}</ul>
            {job.checklist.client_confirmation ? (
              <p className="rounded-lg bg-success/10 p-3 text-xs text-ink">
                Client confirmed via {job.checklist.client_confirmation.channel} — recorded by {job.checklist.client_confirmation.recorded_by} · {formatDateTime(job.checklist.client_confirmation.recorded_at)}
                {job.checklist.client_confirmation.comment ? ` · “${job.checklist.client_confirmation.comment}”` : ""}
                {job.checklist.client_confirmation.evidence_url && <> · <a className="text-brand hover:underline" href={job.checklist.client_confirmation.evidence_url}>Evidence</a></>}
              </p>
            ) : (
              a?.confirm_checklist && <Button size="sm" className="self-start" disabled={!checklistDone} onClick={() => setModal("confirm")}>Record client confirmation</Button>
            )}
          </div>
        )}
      </Section>

      <Section title="Quality checks" description="A mandatory check must pass. Failures and N/A need a note.">
        {!job.quality_checks ? (
          <EmptyState title={job.cutter ? "No quality checks are configured" : "Assign a cutter to start the quality checks"} />
        ) : (
          <ul className="flex flex-col gap-2">{job.quality_checks.items.map((i) => <QualityRow key={`${i.id}-${job.version}`} job={job} item={i} />)}</ul>
        )}
      </Section>

      <Section title="Production issues" description="Damaged pieces, incorrect cuts, missing parts and other problems reported on this job.">
        {(job.issues ?? []).length === 0 ? (
          <EmptyState title="No issues reported" />
        ) : (
          <ul className="flex flex-col gap-2 text-xs">
            {job.issues?.map((i) => (
              <li key={i.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-line p-3">
                <Link href={`/issues/view/?id=${i.id}`} className="font-mono font-bold text-brand hover:underline">#{i.id}</Link>
                <Badge tone={issueStatusTone(i.status.value as IssueStatus)} dot>{i.status.label}</Badge>
                <strong className="text-ink">{i.category.label}</strong>
                {i.quantity_affected !== null && <span>× {i.quantity_affected}</span>}
                {i.size && <span className="text-muted">size {i.size}</span>}
                {i.part && <span className="text-muted">({i.part})</span>}
                {i.is_blocking && <Badge tone="danger">Blocking</Badge>}
                <span className="text-muted">— {i.description} · {i.reported_by} · {formatDateTime(i.reported_at)}{i.assigned_to ? ` · assigned to ${i.assigned_to}` : ""}</span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      {job.completion?.completed_at && (
        <Section title="Completion">
          <dl className="grid gap-x-6 sm:grid-cols-2">
            <Value label="Completed by">{job.completion.completed_by}</Value>
            <Value label="Completed at">{formatDateTime(job.completion.completed_at)}</Value>
            {job.completion.notes && <Value label="Notes">{job.completion.notes}</Value>}
          </dl>
        </Section>
      )}

      {(job.assignment?.history ?? []).length > 0 && (
        <Section title="Assignment history">
          <ul className="flex flex-col gap-1.5 text-xs text-muted">
            {job.assignment?.history.map((h, i) => (
              <li key={i}>
                <strong className="text-ink">{h.cutter}</strong> by {formatBusinessDate(h.expected_completion_date)} — assigned by {h.by} · {formatDateTime(h.at)}{h.reason ? ` · Reason: ${h.reason}` : ""}{h.notes ? ` · ${h.notes}` : ""}
              </li>
            ))}
          </ul>
        </Section>
      )}

      {(job.history ?? []).length > 0 && (
        <Section title="Status history">
          <ul className="flex flex-col gap-1.5 text-xs text-muted">
            {job.history?.map((h, i) => (
              <li key={i}>
                <strong className="text-ink">{h.to}</strong>{h.from ? ` (from ${h.from})` : ""} — {h.by} · {formatDateTime(h.at)}{h.note ? ` · ${h.note}` : ""}
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Modal open={modal === "assign"} title={job.cutter ? "Reassign cutter" : "Assign cutter"} onClose={close}>{modal === "assign" && <AssignForm job={job} onDone={close} />}</Modal>
      <Modal open={modal === "confirm"} title="Client confirmation of the cutting checklist" onClose={close}>{modal === "confirm" && <ConfirmForm job={job} onDone={close} />}</Modal>
      <Modal open={modal === "complete"} title="Complete cutting" onClose={close}>{modal === "complete" && <CompleteForm job={job} onDone={close} />}</Modal>
      <Modal open={modal === "issue"} title="Report a production issue" onClose={close}>
        {modal === "issue" && (
          <IssueForm
            sizes={(job.quantities ?? []).map((q) => q.size)}
            pending={mutations.reportIssue.isPending}
            error={mutations.reportIssue.error}
            onCancel={close}
            onSubmit={(input) => mutations.reportIssue.mutate(input, { onSuccess: () => (notify("Issue reported. The Operation Admin was notified.", "success"), close()) })}
          />
        )}
      </Modal>
    </div>
  );
}

export function CuttingJobScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Cutting job not found" /> : <CuttingJobDetail id={id} />)}</WithIdParam>;
}
