"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { DownloadIcon, ExternalLinkIcon, FileTextIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import { APPROVAL_CHANNELS } from "@/features/designs/api";
import {
  type PrintDocumentAccess,
  type PrintDocumentType,
  type PrintJob,
  type PrintMeasurement,
  gatePassNumberError,
  printServerDocument,
  printStatusTone,
  sampleImageError,
  sampleTone,
  usePrintJob,
  usePrintJobCard,
  usePrintMutations,
  usePrintNameList,
  usePrintRecipients,
} from "./api";

const fileInput =
  "text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer";

type ModalKind = "send" | "sample" | "review" | "start" | "complete" | "gate-pass";

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

function Value({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-line/60 py-2">
      <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-ink">{children}</dd>
    </div>
  );
}

const measure = (m: PrintMeasurement | null) => (m === null ? "—" : m.value === null ? <span className="text-danger">Not entered</span> : `${m.value} ${m.unit}`);

function SendForm({ job, onDone }: { job: PrintJob; onDone: () => void }) {
  const m = usePrintMutations(job.id);
  const recipients = usePrintRecipients(true);
  const { notify } = useToast();
  const [supplier, setSupplier] = useState("");
  const [recipient, setRecipient] = useState("");
  const [notes, setNotes] = useState("");

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(m.send.error)} />
      <SelectField
        label="Printing company (optional)"
        options={[{ value: "", label: "Not a registered supplier" }, ...(recipients.data?.suppliers ?? []).map((s) => ({ value: String(s.id), label: s.name }))]}
        value={supplier}
        onChange={(e) => setSupplier(e.target.value)}
      />
      <TextField label="Recipient / printing contact (optional)" maxLength={160} value={recipient} onChange={(e) => setRecipient(e.target.value)} />
      <TextAreaField label="Notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          loading={m.send.isPending}
          onClick={() => m.send.mutate({ supplier_id: supplier ? Number(supplier) : null, recipient_name: recipient, notes }, { onSuccess: () => (notify("Job sent for printing. The Page Owner was notified.", "success"), onDone()) })}
        >
          Send for printing
        </Button>
      </div>
    </div>
  );
}

function SampleForm({ job, onDone }: { job: PrintJob; onDone: () => void }) {
  const m = usePrintMutations(job.id);
  const { notify } = useToast();
  const [image, setImage] = useState<File | null>(null);
  const [documents, setDocuments] = useState<File[]>([]);
  const [notes, setNotes] = useState("");
  const imageError = image ? sampleImageError(image) : null;
  const corrected = job.status.value === "sample_rejected";

  return (
    <div className="flex flex-col gap-4">
      {corrected && <p className="rounded-lg bg-warning/10 p-3 text-xs text-ink">The previous sample was rejected. This upload becomes a new sample version; earlier samples are kept.</p>}
      <FormError message={errorMessage(m.uploadSample.error)} />
      <label className="flex flex-col gap-1.5 text-xs font-semibold text-ink">
        Sample image * (JPG, PNG or WebP, max 10 MB)
        <input type="file" accept="image/jpeg,image/png,image/webp" className={fileInput} onChange={(e) => setImage(e.target.files?.[0] ?? null)} />
      </label>
      {imageError && <p className="text-xs text-danger">{imageError}</p>}
      <label className="flex flex-col gap-1.5 text-xs font-semibold text-ink">
        Related documents (optional, image or PDF, up to 5)
        <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setDocuments(Array.from(e.target.files ?? []).slice(0, 5))} />
      </label>
      <TextAreaField label="Sample notes" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={!image || imageError !== null}
          loading={m.uploadSample.isPending}
          onClick={() => image && m.uploadSample.mutate({ image, documents, notes }, { onSuccess: () => (notify("Sample uploaded. The Page Owner was notified.", "success"), onDone()) })}
        >
          Upload {corrected ? "corrected sample" : "sample"}
        </Button>
      </div>
    </div>
  );
}

function ReviewForm({ job, onDone }: { job: PrintJob; onDone: () => void }) {
  const m = usePrintMutations(job.id);
  const { notify } = useToast();
  const [decision, setDecision] = useState<"approve" | "reject">("approve");
  const [text, setText] = useState("");
  const [channel, setChannel] = useState("");
  const sample = job.samples?.find((s) => s.is_current);
  const reasonMissing = decision === "reject" && text.trim().length < 3;
  const error = decision === "approve" ? m.approve.error : m.reject.error;

  return (
    <div className="flex flex-col gap-4">
      {sample?.image_url && (
        // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
        <img src={sample.image_url} alt={`Printing sample ${sample.version}`} className="max-h-64 rounded-lg border border-line object-contain" />
      )}
      <FormError message={errorMessage(error)} />
      <SelectField
        label="Decision"
        options={[
          { value: "approve", label: "Approve — physical printing may proceed" },
          { value: "reject", label: "Reject — a corrected sample is needed" },
        ]}
        value={decision}
        onChange={(e) => setDecision(e.target.value as "approve" | "reject")}
      />
      <SelectField label="Customer channel (optional)" options={[{ value: "", label: "Not given by the customer" }, ...APPROVAL_CHANNELS]} value={channel} onChange={(e) => setChannel(e.target.value)} />
      <TextAreaField
        label={decision === "reject" ? "Rejection reason *" : "Comment"}
        maxLength={decision === "reject" ? 1000 : 2000}
        value={text}
        onChange={(e) => setText(e.target.value)}
      />
      {decision === "reject" && reasonMissing && <p className="text-xs text-muted">A rejection reason is required.</p>}
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={reasonMissing}
          loading={m.approve.isPending || m.reject.isPending}
          onClick={() =>
            decision === "approve"
              ? m.approve.mutate({ comment: text, channel }, { onSuccess: () => (notify("Sample approved. The Operation Admin was notified.", "success"), onDone()) })
              : m.reject.mutate({ reason: text, channel }, { onSuccess: () => (notify("Sample rejected. The Operation Admin was notified.", "success"), onDone()) })
          }
        >
          {decision === "approve" ? "Approve sample" : "Reject sample"}
        </Button>
      </div>
    </div>
  );
}

function PrintingForm({ job, kind, onDone }: { job: PrintJob; kind: "start" | "complete"; onDone: () => void }) {
  const m = usePrintMutations(job.id);
  const { notify } = useToast();
  const [notes, setNotes] = useState("");
  const [quantity, setQuantity] = useState(String(job.quantity));
  const qty = Number(quantity);
  const invalid = kind === "complete" && (!Number.isInteger(qty) || qty < 1);
  const mutation = kind === "start" ? m.start : m.complete;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(mutation.error)} />
      {kind === "complete" && <TextField label="Printed quantity *" type="number" min={1} inputMode="numeric" value={quantity} onChange={(e) => setQuantity(e.target.value)} hint={`Ordered quantity: ${job.quantity}`} />}
      <TextAreaField label="Notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={invalid}
          loading={mutation.isPending}
          onClick={() =>
            kind === "start"
              ? m.start.mutate(notes, { onSuccess: () => (notify("Physical printing started", "success"), onDone()) })
              : m.complete.mutate({ printed_quantity: qty, notes }, { onSuccess: () => (notify("Printing completed", "success"), onDone()) })
          }
        >
          {kind === "start" ? "Start physical printing" : "Mark printing completed"}
        </Button>
      </div>
    </div>
  );
}

function GatePassForm({ job, onDone }: { job: PrintJob; onDone: () => void }) {
  const m = usePrintMutations(job.id);
  const { notify } = useToast();
  const [number, setNumber] = useState("");
  const [date, setDate] = useState("");
  const [document, setDocument] = useState<File | null>(null);
  const [notes, setNotes] = useState("");
  const numberError = number ? gatePassNumberError(number) : null;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={errorMessage(m.gatePass.error)} />
      <TextField label="Gate pass number *" maxLength={50} value={number} error={numberError ?? undefined} onChange={(e) => setNumber(e.target.value)} />
      <TextField label="Gate pass date *" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      <label className="flex flex-col gap-1.5 text-xs font-semibold text-ink">
        Gate pass document (optional, image or PDF, max 10 MB)
        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setDocument(e.target.files?.[0] ?? null)} />
      </label>
      <TextAreaField label="Notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={!number || numberError !== null || !date}
          loading={m.gatePass.isPending}
          onClick={() => m.gatePass.mutate({ gate_pass_no: number, gate_pass_date: date, document, notes }, { onSuccess: () => (notify("Gate pass recorded", "success"), onDone()) })}
        >
          Record gate pass
        </Button>
      </div>
    </div>
  );
}

function PrintButton({ jobId, document, access }: { jobId: number; document: PrintDocumentType; access: PrintDocumentAccess | undefined }) {
  const { notify } = useToast();
  const [busy, setBusy] = useState(false);
  const printable = access?.printable === true;

  return (
    <div className="flex flex-col items-end gap-1">
      <Button
        size="sm"
        variant="secondary"
        disabled={!printable}
        loading={busy}
        onClick={() => {
          setBusy(true);
          printServerDocument(jobId, document)
            .catch((e: unknown) => notify(e instanceof ApiError ? e.message : "The document could not be printed.", "error"))
            .finally(() => setBusy(false));
        }}
      >
        Print
      </Button>
      {!printable && access?.print_locked_reason && <span className="max-w-xs text-right text-[11px] text-muted">{access.print_locked_reason}</span>}
    </div>
  );
}

/** On-screen review only. A browser print of this screen shows a notice instead of the document. */
function NotForPrinting({ children }: { children: React.ReactNode }) {
  return (
    <>
      <div className="print:hidden">{children}</div>
      <p className="hidden print:block">This screen is for review only. Print documents from the Print button once printing is approved.</p>
    </>
  );
}

function JobCardSection({ job }: { job: PrintJob }) {
  const [open, setOpen] = useState(false);
  const card = usePrintJobCard(job.id, open);

  return (
    <Section title="Job Card" description="Live from the order, the approved design, the bulk measurements and the confirmed list.">
      <div id="job-card" className="flex flex-col gap-3">
        <div className="flex items-start justify-between gap-2">
          <Button size="sm" variant="secondary" onClick={() => setOpen((o) => !o)}>{open ? "Hide Job Card" : "View Job Card"}</Button>
          <PrintButton jobId={job.id} document="job-card" access={job.documents?.job_card} />
        </div>
        {open &&
          (card.isPending ? <LoadingState /> : card.error ? <ErrorState error={card.error} onRetry={() => card.refetch()} /> : (
            <NotForPrinting>
              <dl className="grid gap-x-6 sm:grid-cols-2">
                <Value label="Job No.">{card.data.job_no}</Value>
                <Value label="Customer">{card.data.customer}</Value>
                <Value label="Category">{card.data.product.category}</Value>
                <Value label="Quantity">{card.data.quantity}</Value>
                <Value label="Material">{card.data.material ?? "—"}{card.data.material_details ? ` · ${card.data.material_details}` : ""}</Value>
                <Value label="Due date">{formatBusinessDate(card.data.due_date)}</Value>
                <Value label="Bulk meter">{card.data.bulk_meter?.value ? `${card.data.bulk_meter.value} ${card.data.bulk_meter.unit}` : "—"}</Value>
                <Value label="Patch meter">{card.data.patch_meter?.value ? `${card.data.patch_meter.value} ${card.data.patch_meter.unit}` : "—"}</Value>
                {card.data.other_measurements.map((m) => <Value key={m.key} label={m.label}>{m.value ? `${m.value} ${m.unit}` : "—"}</Value>)}
                {card.data.list && <Value label="List">v{card.data.list.version_no} · {card.data.list.total_quantity} pcs · {card.data.list.by_size.map((s) => `${s.size}: ${s.quantity}`).join(", ")}</Value>}
              </dl>
            </NotForPrinting>
          ))}
      </div>
    </Section>
  );
}

function NameListSection({ job }: { job: PrintJob }) {
  const [open, setOpen] = useState(false);
  const list = usePrintNameList(job.id, open);
  const fields = list.data?.schema.fields ?? [];

  return (
    <Section title="Confirmed name/number list" description={`Version ${job.list.version_no} · ${job.list.total_quantity} pcs (read-only)`}>
      <div id="name-list" className="flex flex-col gap-3">
        <div className="flex items-start justify-between gap-2">
          <Button size="sm" variant="secondary" onClick={() => setOpen((o) => !o)}>{open ? "Hide list" : "View list"}</Button>
          <PrintButton jobId={job.id} document="name-list" access={job.documents?.name_list} />
        </div>
        {open &&
          (list.isPending ? <LoadingState /> : list.error ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
            <NotForPrinting>
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
                    {list.data.items.map((i) => (
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
            </NotForPrinting>
          ))}
      </div>
    </Section>
  );
}

function PrintJobDetail({ id }: { id: number }) {
  const query = usePrintJob(id);
  const [modal, setModal] = useState<ModalKind | null>(null);

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const job = query.data;
  const a = job.abilities;
  const close = () => setModal(null);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Printing — ${job.job_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/printing/" className="hover:text-ink">Printing</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{job.job_no}</span>
          </div>
        }
        description={`${job.order.customer} · ${job.product_category} × ${job.quantity} · ${job.material ?? "No material"} · Due ${formatBusinessDate(job.order.due_date)}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Badge tone={printStatusTone(job.status.value)} dot>{job.status.label}</Badge>
            {a?.send && <Button size="sm" onClick={() => setModal("send")}>Send for printing</Button>}
            {a?.upload_sample && <Button size="sm" onClick={() => setModal("sample")}>{job.status.value === "sample_rejected" ? "Upload corrected sample" : "Upload sample"}</Button>}
            {a?.review_sample && <Button size="sm" onClick={() => setModal("review")}>Review sample</Button>}
            {a?.start_printing && <Button size="sm" onClick={() => setModal("start")}>Start physical printing</Button>}
            {a?.complete_printing && <Button size="sm" onClick={() => setModal("complete")}>Complete printing</Button>}
            {a?.record_gate_pass && <Button size="sm" variant="secondary" onClick={() => setModal("gate-pass")}>Record gate pass</Button>}
          </div>
        }
      />

      <div className="grid gap-3 sm:grid-cols-4">
        <Stat label="Type">{job.mode === "internal" ? "Internal" : "External"}</Stat>
        <Stat label="Bulk by">{job.bulk.producer ?? "—"}</Stat>
        <Stat label="Quantity">{job.quantity}</Stat>
        <Stat label="Job status">{job.order.status.label}</Stat>
      </div>

      <Section title="Printing information">
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
            <Value label="Quantity">{job.quantity}</Value>
            <Value label="Bulk meter">{measure(job.bulk_meter)}</Value>
            <Value label="Patch meter">{measure(job.patch_meter)}</Value>
            {job.collar_meter && <Value label={job.collar_meter.label}>{measure(job.collar_meter)}</Value>}
            {job.other_measurements.map((m) => <Value key={m.key} label={m.label}>{measure(m)}</Value>)}
            <Value label="Bulk files">
              {job.bulk.files.length === 0 ? (
                <span className="text-muted">{job.mode === "external" ? "External bulk (no files)" : "—"}</span>
              ) : (
                <span className="flex flex-col gap-1">
                  {job.bulk.files.map((f) => (
                    <a key={f.id} className="inline-flex items-center gap-1 text-xs text-brand hover:underline" href={f.url}>
                      <DownloadIcon size={12} /> {f.name}
                    </a>
                  ))}
                </span>
              )}
            </Value>
            <Value label="WeTransfer link">
              {job.bulk.transfer_url ? (
                <a className="inline-flex items-center gap-1 text-xs text-brand hover:underline" href={job.bulk.transfer_url} target="_blank" rel="noopener noreferrer nofollow">
                  Open <ExternalLinkIcon size={12} />
                </a>
              ) : (
                "—"
              )}
            </Value>
            <Value label="Bulk job">
              <Link href={`/bulk-jobs/view/?id=${job.bulk.id}`} className="inline-flex items-center gap-1 text-xs text-brand hover:underline">
                <FileTextIcon size={12} /> Completed {formatDateTime(job.bulk.completed_at)}
              </Link>
            </Value>
          </dl>
        </div>
      </Section>

      <JobCardSection job={job} />
      <NameListSection job={job} />

      {job.sending?.sent_at && (
        <Section title="Sent for printing">
          <dl className="grid gap-x-6 sm:grid-cols-2">
            <Value label="Sent by">{job.sending.sent_by}</Value>
            <Value label="Sent at">{formatDateTime(job.sending.sent_at)}</Value>
            <Value label="Printing company">{job.sending.printing_company ?? "—"}</Value>
            <Value label="Recipient">{job.sending.recipient_name ?? "—"}</Value>
            {job.sending.notes && <Value label="Notes">{job.sending.notes}</Value>}
          </dl>
        </Section>
      )}

      <Section title="Printing samples" description="Every sample is kept as a version. Corrections after a rejection never overwrite earlier samples.">
        {(job.samples ?? []).length === 0 ? (
          <EmptyState title="No sample uploaded yet" />
        ) : (
          <div className="overflow-x-auto rounded-xl border border-line">
            <table className="w-full text-xs">
              <caption className="sr-only">Printing sample versions</caption>
              <thead className="bg-subtle text-left text-muted">
                <tr>
                  <th className="px-3 py-2">Version</th>
                  <th className="px-3 py-2">Sample</th>
                  <th className="px-3 py-2">Uploaded by</th>
                  <th className="px-3 py-2">Uploaded at</th>
                  <th className="px-3 py-2">Review status</th>
                  <th className="px-3 py-2">Approval / rejection reason</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {job.samples?.map((s) => (
                  <tr key={s.id} className="align-top">
                    <td className="px-3 py-2 font-bold text-ink">v{s.version}{s.is_current && <Badge tone="brand" className="ml-2">Current</Badge>}</td>
                    <td className="px-3 py-2">
                      <div className="flex flex-col gap-1">
                        {s.image_url && (
                          <a href={s.image_url} target="_blank" rel="noopener noreferrer">
                            {/* eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL */}
                            <img src={s.image_url} alt={`Printing sample ${s.version}`} className="h-16 w-16 rounded border border-line object-cover" />
                          </a>
                        )}
                        {s.documents.map((d) => (
                          <a key={d.url} className="inline-flex items-center gap-1 text-brand hover:underline" href={d.url}><DownloadIcon size={12} /> {d.name}</a>
                        ))}
                        {s.notes && <span className="text-muted">{s.notes}</span>}
                      </div>
                    </td>
                    <td className="px-3 py-2">{s.uploaded_by ?? "—"}</td>
                    <td className="px-3 py-2">{formatDateTime(s.uploaded_at)}</td>
                    <td className="px-3 py-2">
                      <Badge tone={sampleTone(s.review_status.value)} dot>{s.review_status.label}</Badge>
                      {s.reviewed_by && <div className="mt-1 text-muted">{s.reviewed_by} · {formatDateTime(s.reviewed_at)}</div>}
                    </td>
                    <td className="px-3 py-2">{s.rejection_reason ?? s.comment ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      {job.printing?.started_at && (
        <Section title="Physical printing">
          <dl className="grid gap-x-6 sm:grid-cols-2">
            <Value label="Started">{job.printing.started_by} · {formatDateTime(job.printing.started_at)}</Value>
            <Value label="Completed">{job.printing.completed_at ? `${job.printing.completed_by} · ${formatDateTime(job.printing.completed_at)}` : "In progress"}</Value>
            {job.printing.printed_quantity !== null && <Value label="Printed quantity">{job.printing.printed_quantity}</Value>}
            {job.printing.notes && <Value label="Notes">{job.printing.notes}</Value>}
          </dl>
        </Section>
      )}

      {(job.printing?.completed_at || (job.gate_passes ?? []).length > 0) && (
        <Section title="Gate passes" description="Recorded after physical printing.">
          {(job.gate_passes ?? []).length === 0 ? (
            <EmptyState title="No gate pass recorded" />
          ) : (
            <ul className="flex flex-col gap-2 text-xs">
              {job.gate_passes?.map((g) => (
                <li key={g.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-line p-3">
                  <span className="font-mono font-bold text-ink">{g.gate_pass_no}</span>
                  <span className="text-muted">{formatBusinessDate(g.gate_pass_date)}</span>
                  <span className="text-muted">· {g.uploaded_by} · {formatDateTime(g.uploaded_at)}</span>
                  {g.document && <a className="inline-flex items-center gap-1 text-brand hover:underline" href={g.document.url}><DownloadIcon size={12} /> {g.document.name}</a>}
                  {g.notes && <span className="text-muted">· {g.notes}</span>}
                </li>
              ))}
            </ul>
          )}
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

      <Modal open={modal === "send"} title="Send for printing" onClose={close}>{modal === "send" && <SendForm job={job} onDone={close} />}</Modal>
      <Modal open={modal === "sample"} title="Upload printing sample" onClose={close}>{modal === "sample" && <SampleForm job={job} onDone={close} />}</Modal>
      <Modal open={modal === "review"} title="Review printing sample" onClose={close}>{modal === "review" && <ReviewForm job={job} onDone={close} />}</Modal>
      <Modal open={modal === "start" || modal === "complete"} title={modal === "complete" ? "Complete physical printing" : "Start physical printing"} onClose={close}>
        {(modal === "start" || modal === "complete") && <PrintingForm job={job} kind={modal} onDone={close} />}
      </Modal>
      <Modal open={modal === "gate-pass"} title="Record gate pass" onClose={close}>{modal === "gate-pass" && <GatePassForm job={job} onDone={close} />}</Modal>
    </div>
  );
}

export function PrintJobScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Printing job not found" /> : <PrintJobDetail id={id} />)}</WithIdParam>;
}
