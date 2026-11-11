"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { DataTable, type Column } from "@/components/ui/data-table";
import { TextField } from "@/components/ui/field";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { ChevronRightIcon, DownloadIcon, ExternalLinkIcon, PaletteIcon, PlusIcon, SearchIcon, UserIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { RequirePermission, WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { ApiError } from "@/lib/api-client";
import { formatBusinessDate, formatDateTime } from "@/lib/format";
import { useDebounced } from "@/lib/use-debounced";
import { APPROVAL_CHANNELS, type DesignTask, isAllowedDesignLink, useDesigners, useDesignMutations, useDesignTask, useDesignTasks } from "./api";

const tone = (s: string) => (s === "completed" ? "success" : s === "changes_requested" ? "danger" : s === "in_review" || s === "approved" ? "brand" : "neutral");

/** Centralised production plan: earliest due date first (urgent jobs land in their position automatically). */
export function DesignDashboard() {
  const [params, setParams] = useState({ page: 1, search: "", open: true, sort: "due_date" });
  const query = useDesignTasks(useDebounced(params));

  const columns: Column<DesignTask>[] = [
    {
      key: "job",
      header: "Job No.",
      cell: (t) => (
        <Link href={`/designs/view/?id=${t.id}`} className="font-mono text-xs font-bold text-brand bg-brand/10 px-2 py-0.5 rounded hover:bg-brand/20 transition">
          {t.order.order_no}
        </Link>
      ),
    },
    { key: "where", header: "Company / Shop", cell: (t) => <span className="text-xs text-muted">{t.order.page.code}</span> },
    { key: "customer", header: "Customer", cell: (t) => <span className="font-medium text-ink">{t.order.customer}</span> },
    { key: "category", header: "Category", cell: (t) => t.order.product_category },
    { key: "qty", header: "Qty", cell: (t) => <span className="font-semibold text-ink">{t.order.quantity}</span> },
    {
      key: "due",
      header: "Due date",
      sortKey: "due_date",
      cell: (t) => (
        <div className="flex items-center gap-1.5">
          <span className="text-xs font-medium text-ink">{formatBusinessDate(t.order.due_date)}</span>
          {t.order.is_urgent && <Badge tone="danger" dot>Urgent</Badge>}
        </div>
      ),
    },
    { key: "status", header: "Design Status", cell: (t) => <Badge tone={tone(t.status.value)} dot>{t.status.label}</Badge> },
    {
      key: "designer",
      header: "Assigned Designer",
      cell: (t) =>
        t.designer ? (
          <span className="inline-flex items-center gap-1 text-xs font-medium text-ink">
            <span className="size-1.5 rounded-full bg-brand" />
            {t.designer.name}
          </span>
        ) : (
          <span className="text-xs font-semibold text-danger">Unassigned</span>
        ),
    },
    {
      key: "actions",
      header: "Actions",
      className: "text-right",
      cell: (t) => (
        <div className="flex items-center justify-end gap-1.5">
          <Link
            href={`/designs/view/?id=${t.id}`}
            className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-brand/40 hover:bg-subtle hover:text-brand transition shrink-0"
          >
            <span>View</span>
            <ChevronRightIcon size={13} />
          </Link>
        </div>
      ),
    },
  ];

  return (
    <RequirePermission anyOf={["designs.view", "designs.view_assigned"]}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Design plan" description="All design jobs sorted by earliest target due date. Designers see the jobs assigned to them." />
        <div className="flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between">
          <div className="w-full sm:max-w-md">
            <TextField
              label="Search design plan"
              placeholder="Search by Job no. or customer name…"
              icon={<SearchIcon size={16} />}
              maxLength={100}
              value={params.search}
              onChange={(e) => setParams((p) => ({ ...p, page: 1, search: e.target.value }))}
            />
          </div>
          <div className="pt-4 sm:pt-0">
            <Checkbox label="Hide completed jobs" checked={params.open} onChange={(e) => setParams((p) => ({ ...p, page: 1, open: e.target.checked }))} />
          </div>
        </div>
        <DataTable
          caption="Design plan"
          columns={columns}
          result={query.data}
          isLoading={query.isPending}
          error={query.error}
          onRetry={() => query.refetch()}
          rowKey={(t) => t.id}
          sort={params.sort}
          onSortChange={(sort) => setParams((p) => ({ ...p, page: 1, sort }))}
          onPageChange={(page) => setParams((p) => ({ ...p, page }))}
          emptyTitle="No design jobs in queue"
        />
      </div>
    </RequirePermission>
  );
}

function SubmissionForm({ final, onSubmit, pending, error, onCancel }: { final: boolean; onSubmit: (v: { preview: File | null; files: File[]; external_url: string; notes: string }) => void; pending: boolean; error: unknown; onCancel: () => void }) {
  const [preview, setPreview] = useState<File | null>(null);
  const [files, setFiles] = useState<File[]>([]);
  const [url, setUrl] = useState("");
  const [notes, setNotes] = useState("");
  const apiError = error instanceof ApiError ? error : null;
  const urlHint = url !== "" && !isAllowedDesignLink(url) ? "Use an https Google Drive / Docs link." : undefined;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={apiError ? (Object.values(apiError.fieldErrors)[0]?.[0] ?? apiError.message) : null} />
      {!final && (
        <div className="flex flex-col gap-1.5">
          <label htmlFor="design-preview" className="text-xs font-semibold text-ink">Preview mockup image (JPG, PNG, WebP)</label>
          <input id="design-preview" type="file" accept="image/jpeg,image/png,image/webp" className="text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer" onChange={(e) => setPreview(e.target.files?.[0] ?? null)} />
        </div>
      )}
      <div className="flex flex-col gap-1.5">
        <label htmlFor="design-files" className="text-xs font-semibold text-ink">{final ? "Final Vector / Production files (PDF, CDR)" : "Draft vector files (PDF, CDR)"}</label>
        <input id="design-files" type="file" multiple accept=".pdf,.cdr,application/pdf" className="text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer" onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 5))} />
      </div>
      <TextField label={final ? "Final Google Drive Link" : "Google Drive Proof Link"} type="url" maxLength={500} placeholder="https://drive.google.com/..." value={url} error={urlHint} hint="Link is stored securely for staff review. The server never scrapes external credentials." onChange={(e) => setUrl(e.target.value.trim())} />
      <TextAreaField label="Designer notes / specifications" placeholder="Special printing colors, placement notes, etc." maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onCancel}>Cancel</Button>
        <Button loading={pending} disabled={urlHint !== undefined || (!preview && files.length === 0 && url === "")} onClick={() => onSubmit({ preview, files, external_url: url, notes })}>
          {final ? "Submit final files" : "Submit for review"}
        </Button>
      </div>
    </div>
  );
}

function ReviewForm({ task, onDone }: { task: DesignTask; onDone: () => void }) {
  const { requestCorrection, approve } = useDesignMutations(task.id);
  const { notify } = useToast();
  const [mode, setMode] = useState<"approve" | "correct">("approve");
  const [channel, setChannel] = useState("whatsapp");
  const [text, setText] = useState("");
  const [override, setOverride] = useState("");
  const limitReached = task.max_correction_rounds !== undefined && task.correction_rounds >= task.max_correction_rounds;
  const error = mode === "approve" ? approve.error : requestCorrection.error;

  const submit = () =>
    mode === "approve"
      ? approve.mutate({ channel, comment: text }, { onSuccess: () => (notify("Final design approved. The designer was notified for final files.", "success"), onDone()) })
      : requestCorrection.mutate({ reason: text, channel, ...(override ? { override_reason: override } : {}) }, { onSuccess: () => (notify("Correction requested", "success"), onDone()) });

  return (
    <div className="flex flex-col gap-4">
      <FormError message={error instanceof ApiError ? error.message : null} />
      <SelectField label="Review decision" options={[{ value: "approve", label: "Approve final design (Customer confirmed)" }, { value: "correct", label: "Request changes / correction" }]} value={mode} onChange={(e) => setMode(e.target.value as "approve" | "correct")} />
      <SelectField label="Customer communication channel" options={APPROVAL_CHANNELS} value={channel} onChange={(e) => setChannel(e.target.value)} />
      <TextAreaField label={mode === "approve" ? "Customer confirmation remarks" : "Correction requested by customer *"} placeholder={mode === "approve" ? "e.g. Confirmed on WhatsApp chat" : "e.g. Change font to Impact and adjust logo height by 2cm"} maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} />
      {mode === "correct" && limitReached && (
        <div className="rounded-xl border border-danger/30 bg-danger-soft p-3">
          <TextField label="Reason for exceeding correction limit" hint={`The configured limit of ${task.max_correction_rounds} round(s) has been reached. Only privileged managers can authorize an override.`} maxLength={500} value={override} onChange={(e) => setOverride(e.target.value)} />
        </div>
      )}
      <div className="flex justify-end gap-2.5 pt-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button variant={mode === "approve" ? "primary" : "danger"} disabled={mode === "correct" && text.trim().length < 3} loading={approve.isPending || requestCorrection.isPending} onClick={submit}>
          {mode === "approve" ? "Approve Design" : "Submit Correction Request"}
        </Button>
      </div>
    </div>
  );
}

function DesignDetail({ id }: { id: number }) {
  const query = useDesignTask(id);
  const m = useDesignMutations(id);
  const { notify } = useToast();
  const [modal, setModal] = useState<"version" | "final" | "review" | "assign" | null>(null);
  const designers = useDesigners(modal === "assign");
  const [designerId, setDesignerId] = useState("");

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const t = query.data;
  const a = t.abilities;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Design — ${t.order.order_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/designs/" className="hover:text-ink">Design Plan</Link>
            <span>/</span>
            <span className="font-semibold text-ink">Job {t.order.order_no}</span>
          </div>
        }
        description={`Client: ${t.order.customer} · ${t.order.product_category} × ${t.order.quantity} · Target due ${formatBusinessDate(t.order.due_date)}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Badge tone={tone(t.status.value)} dot>{t.status.label}</Badge>
            {a?.assign && <Button variant="secondary" size="sm" onClick={() => setModal("assign")}>Assign designer</Button>}
            {a?.upload_version && <Button size="sm" onClick={() => setModal("version")}>Upload design</Button>}
            {a?.request_correction && <Button size="sm" onClick={() => setModal("review")}>Review design</Button>}
            {a?.submit_final && <Button size="sm" onClick={() => setModal("final")}>Submit final files</Button>}
          </div>
        }
      />

      {/* Designer & Job Scope Info Banner */}
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Assigned Designer</span>
          <span className="text-sm font-bold text-ink">{t.designer?.name ?? <span className="text-danger">Unassigned</span>}</span>
        </div>
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Correction Rounds</span>
          <span className="text-sm font-bold text-ink">
            {t.correction_rounds} <span className="font-normal text-muted">of {t.max_correction_rounds ?? "unlimited"} allowed</span>
          </span>
        </div>
        <div className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 shadow-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Production Job</span>
          <Link href={`/orders/view/?id=${t.order.id}`} className="text-sm font-mono font-bold text-brand hover:underline">
            {t.order.order_no} →
          </Link>
        </div>
      </div>

      {t.job_details && (
        <Section title="Design Instructions & Specifications">
          <dl className="grid gap-3 text-sm sm:grid-cols-2">
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Fabric / Material</dt>
              <dd className="font-semibold text-ink">{t.job_details.material ?? "—"} {t.job_details.material_details ? `· ${t.job_details.material_details}` : ""}</dd>
            </div>
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Client Attachment</dt>
              <dd>
                {t.job_details.attachment ? (
                  <a className="inline-flex items-center gap-1 font-semibold text-brand hover:underline" href={t.job_details.attachment.url} target="_blank" rel="noopener noreferrer">
                    <span>Open customer sample</span>
                    <ExternalLinkIcon size={13} />
                  </a>
                ) : (
                  <span className="text-muted">None attached</span>
                )}
              </dd>
            </div>
            <div className="sm:col-span-2 flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Artwork Instructions</dt>
              <dd className="whitespace-pre-wrap text-ink">{t.job_details.design_instructions ?? "—"}</dd>
            </div>
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Custom Notes</dt>
              <dd className="whitespace-pre-wrap text-ink">{t.job_details.custom_notes ?? "—"}</dd>
            </div>
            <div className="flex flex-col gap-1 rounded-lg bg-subtle/50 p-3">
              <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">Overall Remarks</dt>
              <dd className="whitespace-pre-wrap text-ink">{t.job_details.overall_remarks ?? "—"}</dd>
            </div>
          </dl>
        </Section>
      )}

      {/* Design Versions & Proof Gallery */}
      <Section title="Design Versions & Approvals" description="All uploaded design revisions are preserved immutably with review records.">
        {(t.versions ?? []).length === 0 ? (
          <EmptyState title="No design versions submitted yet" description="The assigned designer can upload initial mockups using the button above." />
        ) : (
          <div className="flex flex-col gap-4">
            {(t.versions ?? []).map((v) => (
              <div key={v.id} className="rounded-xl border border-line bg-surface p-5 shadow-xs transition hover:border-brand/30">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line/60 pb-3">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-bold text-ink">Version {v.version_no}</span>
                    {v.is_final && <Badge tone="success" dot>Final Production Files</Badge>}
                    {v.is_approved && <Badge tone="success">Approved</Badge>}
                  </div>
                  <span className="text-xs text-muted">
                    Uploaded by <strong className="font-semibold text-ink">{v.uploaded_by}</strong> on {formatDateTime(v.uploaded_at)}
                  </span>
                </div>
                {v.notes && <p className="mt-3 text-xs text-ink bg-subtle/50 p-3 rounded-lg leading-relaxed">{v.notes}</p>}

                {/* Files & Proofs */}
                <div className="mt-4 flex flex-wrap gap-4">
                  {v.files.map((f) =>
                    f.kind === "preview" ? (
                      // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL
                      <div key={f.id} className="overflow-hidden rounded-xl border border-line bg-subtle/30 p-2 max-w-sm">
                        <img src={f.url} alt={`Preview of version ${v.version_no}`} className="max-h-56 w-auto rounded-lg object-contain shadow-xs" />
                      </div>
                    ) : (
                      <a key={f.id} className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-2 text-xs font-semibold text-brand shadow-xs hover:bg-subtle transition" href={f.url}>
                        <DownloadIcon size={14} />
                        <span>Download {f.name}</span>
                      </a>
                    ),
                  )}
                  {v.external_url && (
                    <a className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-2 text-xs font-semibold text-brand shadow-xs hover:bg-subtle transition" href={v.external_url} target="_blank" rel="noopener noreferrer nofollow">
                      <ExternalLinkIcon size={14} />
                      <span>Google Drive Proof Assets</span>
                    </a>
                  )}
                </div>

                {/* Review feedback */}
                {v.reviews.length > 0 && (
                  <div className="mt-4 border-t border-line/60 pt-3">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Customer Review Outcome</span>
                    {v.reviews.map((r, i) => (
                      <p key={i} className="mt-1 text-xs text-muted">
                        <Badge tone={r.decision === "approved" ? "success" : "danger"} className="mr-2">
                          {r.decision === "approved" ? "Approved" : "Correction Requested"}
                        </Badge>
                        via <strong className="capitalize text-ink">{r.channel}</strong> recorded by <strong className="text-ink">{r.recorded_by}</strong> on {formatDateTime(r.at)}
                        {r.comment ? ` · "${r.comment}"` : ""}
                      </p>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* Correction History */}
      {(t.corrections ?? []).length > 0 && (
        <Section title="Correction History Log">
          <ol className="relative ml-3 border-l border-line/70 space-y-4 text-xs">
            {(t.corrections ?? []).map((c) => (
              <li key={c.round_no} className="ml-4">
                <span className="absolute -left-1.5 mt-1.5 size-3 rounded-full border-2 border-surface bg-danger" />
                <div className="flex flex-wrap items-center gap-x-2">
                  <span className="font-bold text-ink">Round {c.round_no}</span>
                  <span className="text-muted">· {formatDateTime(c.requested_at)} by {c.requested_by}</span>
                  <Badge tone={c.resolved_in_version_id ? "success" : "neutral"} className="text-[10px]">
                    {c.resolved_in_version_id ? "Resolved" : "Open"}
                  </Badge>
                </div>
                <p className="mt-1 text-ink">{c.reason}</p>
                {c.override_reason && <p className="mt-1 font-semibold text-danger">Limit override: {c.override_reason}</p>}
              </li>
            ))}
          </ol>
        </Section>
      )}

      {/* Modals */}
      <Modal open={modal === "version" || modal === "final"} title={modal === "final" ? "Submit Final Production Files" : "Upload Design Draft"} onClose={() => setModal(null)}>
        {(modal === "version" || modal === "final") && (
          <SubmissionForm
            final={modal === "final"}
            pending={m.submitVersion.isPending || m.submitFinal.isPending}
            error={modal === "final" ? m.submitFinal.error : m.submitVersion.error}
            onCancel={() => setModal(null)}
            onSubmit={(v) => (modal === "final" ? m.submitFinal : m.submitVersion).mutate(v, { onSuccess: () => (notify("Submitted", "success"), setModal(null)) })}
          />
        )}
      </Modal>

      <Modal open={modal === "review"} title="Review Design with Customer" onClose={() => setModal(null)}>
        {modal === "review" && <ReviewForm task={t} onDone={() => setModal(null)} />}
      </Modal>

      <Modal open={modal === "assign"} title="Assign Responsible Designer" onClose={() => setModal(null)}>
        <div className="flex flex-col gap-4">
          <FormError message={m.assign.error instanceof ApiError ? m.assign.error.message : null} />
          <SelectField label="Select Designer" options={[{ value: "", label: "Choose a designer…" }, ...(designers.data ?? []).map((d) => ({ value: String(d.id), label: d.name }))]} value={designerId} onChange={(e) => setDesignerId(e.target.value)} />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button>
            <Button disabled={!designerId} loading={m.assign.isPending} onClick={() => m.assign.mutate(Number(designerId), { onSuccess: () => (notify("Designer assigned", "success"), setModal(null)) })}>Assign Designer</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

export function DesignScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Design job not found" /> : <DesignDetail id={id} />)}</WithIdParam>;
}
