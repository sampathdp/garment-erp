"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Badge, Checkbox, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { formatDateTime } from "@/lib/format";
import { type IssueStatus, type ProductionIssue, issueStatusTone, useIssue, useIssueAssignees, useIssueMutations } from "./api";
import { apiErrorMessage } from "./issue-form";

function Value({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-line/60 py-2">
      <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-ink">{children}</dd>
    </div>
  );
}

function ManagePanel({ issue }: { issue: ProductionIssue }) {
  const m = useIssueMutations(issue.id);
  const assignees = useIssueAssignees(issue.id, true);
  const { notify } = useToast();
  const [assignee, setAssignee] = useState(issue.assigned_to ? String(issue.assigned_to.id) : "");

  return (
    <Section title="Assignment">
      <div className="flex flex-col gap-3">
        <FormError message={apiErrorMessage(m.update.error)} />
        <div className="flex flex-wrap items-end gap-3">
          <SelectField label="Assigned to" options={[{ value: "", label: "Unassigned" }, ...(assignees.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))]} value={assignee} onChange={(e) => setAssignee(e.target.value)} />
          <Button
            size="sm"
            disabled={assignee === (issue.assigned_to ? String(issue.assigned_to.id) : "")}
            loading={m.update.isPending}
            onClick={() => m.update.mutate({ assigned_to: assignee ? Number(assignee) : null }, { onSuccess: () => notify("Assignment saved", "success") })}
          >
            Save
          </Button>
        </div>
        <Checkbox
          label="Blocking — prevents cutting and job completion while unresolved"
          checked={issue.is_blocking}
          disabled={m.update.isPending}
          onChange={(e) => m.update.mutate({ is_blocking: e.target.checked })}
        />
      </div>
    </Section>
  );
}

function TransitionPanel({ issue }: { issue: ProductionIssue }) {
  const m = useIssueMutations(issue.id);
  const { notify } = useToast();
  const [to, setTo] = useState<IssueStatus | "">("");
  const [text, setText] = useState("");
  const options = issue.next_statuses ?? [];
  const resolving = to === "resolved";
  const reopening = to === "open";
  const missing = (resolving || reopening) && text.trim().length < 3;

  if (options.length === 0) return null;

  return (
    <Section title="Update status">
      <div className="flex flex-col gap-3">
        <FormError message={apiErrorMessage(m.transition.error)} />
        <SelectField label="New status" options={[{ value: "", label: "Choose…" }, ...options]} value={to} onChange={(e) => setTo(e.target.value as IssueStatus | "")} />
        <TextAreaField label={resolving ? "Resolution *" : reopening ? "Reason for reopening *" : "Note"} maxLength={2000} value={text} onChange={(e) => setText(e.target.value)} />
        <Button
          size="sm"
          className="self-start"
          disabled={!to || missing}
          loading={m.transition.isPending}
          onClick={() =>
            to &&
            m.transition.mutate(
              { to, note: resolving ? "" : text.trim(), resolution: resolving ? text.trim() : "" },
              { onSuccess: () => (notify("Status updated", "success"), setTo(""), setText("")) },
            )
          }
        >
          Update
        </Button>
      </div>
    </Section>
  );
}

function IssueDetail({ id }: { id: number }) {
  const query = useIssue(id);

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const issue = query.data;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Issue #${issue.id} — ${issue.category.label}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/issues/" className="hover:text-ink">Production issues</Link>
            <span>/</span>
            <span className="font-semibold text-ink">#{issue.id}</span>
          </div>
        }
        description={`${issue.job_no} · ${issue.order.customer} · ${issue.page.code}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={issueStatusTone(issue.status.value)} dot>{issue.status.label}</Badge>
            {issue.is_blocking && <Badge tone="danger">Blocking</Badge>}
          </div>
        }
      />

      <Section title="Issue">
        <div className="grid gap-6 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
          <dl className="grid gap-x-6 sm:grid-cols-2">
            <Value label="Job No.">{issue.job_no}</Value>
            <Value label="Category">{issue.category.label}</Value>
            <Value label="Quantity affected">{issue.quantity_affected ?? "—"}</Value>
            <Value label="Size / part">{[issue.size, issue.part?.label].filter(Boolean).join(" · ") || "—"}</Value>
            <Value label="Reported by">{issue.reported_by} · {formatDateTime(issue.reported_at)}</Value>
            <Value label="Assigned to">{issue.assigned_to?.name ?? "—"}</Value>
            <Value label="Stage">{issue.stage.replace(/_/g, " ")}</Value>
            <Value label="Cutting job">
              {issue.cutting_job_id ? <Link className="text-brand hover:underline" href={`/cutting/view/?id=${issue.cutting_job_id}`}>Open cutting job</Link> : "—"}
            </Value>
            <div className="sm:col-span-2"><Value label="Description">{issue.description}</Value></div>
            {issue.resolution && <div className="sm:col-span-2"><Value label="Resolution">{issue.resolution} — {issue.resolved_by} · {formatDateTime(issue.resolved_at)}</Value></div>}
            {issue.closed_at && <Value label="Closed">{issue.closed_by} · {formatDateTime(issue.closed_at)}</Value>}
          </dl>
          {issue.image_url ? (
            <a href={issue.image_url} target="_blank" rel="noopener noreferrer">
              {/* eslint-disable-next-line @next/next/no-img-element -- signed, short-lived private URL */}
              <img src={issue.image_url} alt={`Image of issue ${issue.id}`} className="w-full rounded-lg border border-line object-contain" />
            </a>
          ) : (
            <div className="flex h-32 items-center justify-center rounded-lg border border-dashed border-line text-xs text-muted">No image</div>
          )}
        </div>
      </Section>

      {issue.abilities?.manage && <ManagePanel key={issue.version} issue={issue} />}
      {issue.abilities?.transition && <TransitionPanel key={`t-${issue.version}`} issue={issue} />}

      <Section title="History">
        <ul className="flex flex-col gap-1.5 text-xs text-muted">
          {(issue.history ?? []).map((h, i) => (
            <li key={i}>
              <strong className="text-ink">{h.to}</strong>{h.from && h.from !== h.to ? ` (from ${h.from})` : ""} — {h.by} · {formatDateTime(h.at)}{h.note ? ` · ${h.note}` : ""}
            </li>
          ))}
        </ul>
      </Section>
    </div>
  );
}

export function IssueScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Issue not found" /> : <IssueDetail id={id} />)}</WithIdParam>;
}
