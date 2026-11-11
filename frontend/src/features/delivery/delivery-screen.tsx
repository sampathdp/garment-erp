"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { Badge, FormError, PageHeader, Section, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { DownloadIcon } from "@/components/ui/icons";
import { Modal } from "@/components/ui/modal";
import { WithIdParam } from "@/components/ui/record-page";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { useToast } from "@/components/ui/toast";
import { formatBusinessDate, formatDateTime, minorToInput, parseMoneyToMinor } from "@/lib/format";
import { apiErrorMessage } from "@/features/issues/issue-form";
import { COMPLETION_GROUPS, DELIVERY_METHODS, DOCUMENT_TYPES, type Delivery, type DeliveryDocumentType, type DeliveryMethod, deliveryStatusTone, requiredDocuments, useDelivery, useDeliveryMutations } from "./api";

const fileInput =
  "text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer";

type ModalKind = "dispatch" | "deliver" | "document" | "complete";

function Value({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-line/60 py-2">
      <dt className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-ink">{children}</dd>
    </div>
  );
}

function DispatchForm({ delivery, onDone }: { delivery: Delivery; onDone: () => void }) {
  const m = useDeliveryMutations(delivery.id);
  const { notify } = useToast();
  const [form, setForm] = useState({ method: "courier" as DeliveryMethod, courier_name: "", tracking_no: "", scheduled_date: "", notes: "" });

  return (
    <div className="flex flex-col gap-4">
      <p className="text-xs text-muted">The cash-on-delivery amount is set to the outstanding balance: {delivery.balance.formatted}. The balance does not have to be paid before dispatch.</p>
      <FormError message={apiErrorMessage(m.dispatch.error)} />
      <SelectField label="Method *" options={DELIVERY_METHODS.filter((x) => x.value !== "pickup")} value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value as DeliveryMethod })} />
      {form.method === "courier" && <TextField label="Courier *" maxLength={80} value={form.courier_name} onChange={(e) => setForm({ ...form, courier_name: e.target.value })} />}
      <TextField label="Tracking / waybill no." maxLength={60} value={form.tracking_no} onChange={(e) => setForm({ ...form, tracking_no: e.target.value })} />
      <TextField label="Scheduled date" type="date" value={form.scheduled_date} onChange={(e) => setForm({ ...form, scheduled_date: e.target.value })} />
      <TextAreaField label="Notes" maxLength={1000} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button disabled={form.method === "courier" && !form.courier_name.trim()} loading={m.dispatch.isPending} onClick={() => m.dispatch.mutate(form, { onSuccess: () => (notify("Job dispatched", "success"), onDone()) })}>
          Dispatch
        </Button>
      </div>
    </div>
  );
}

function DeliverForm({ delivery, onDone }: { delivery: Delivery; onDone: () => void }) {
  const m = useDeliveryMutations(delivery.id);
  const { notify } = useToast();
  const pickup = delivery.status.value === "ready";
  const [receivedBy, setReceivedBy] = useState("");
  const [cod, setCod] = useState(delivery.balance.amount_minor > 0 ? minorToInput(delivery.balance.amount_minor) : "");
  const [notes, setNotes] = useState("");
  const codMinor = cod.trim() === "" ? 0 : parseMoneyToMinor(cod);
  const codError = codMinor === null ? "Enter an amount such as 1,250.00." : codMinor > delivery.balance.amount_minor ? `Cannot exceed the balance ${delivery.balance.formatted}.` : null;

  return (
    <div className="flex flex-col gap-4">
      {pickup && <p className="rounded-lg bg-subtle p-3 text-xs text-ink">The customer collects the job (pickup). Upload a delivery or customer photo before completing the job.</p>}
      <FormError message={apiErrorMessage(m.deliver.error)} />
      <TextField label="Received by *" maxLength={120} value={receivedBy} onChange={(e) => setReceivedBy(e.target.value)} />
      <TextField label={`Cash collected (balance ${delivery.balance.formatted})`} inputMode="decimal" value={cod} onChange={(e) => setCod(e.target.value)} error={codError ?? undefined} hint="Recorded as a cash-on-delivery payment that accounts must verify." />
      <TextAreaField label="Notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button
          disabled={receivedBy.trim().length < 2 || codError !== null}
          loading={m.deliver.isPending}
          onClick={() => m.deliver.mutate({ method: pickup ? "pickup" : null, received_by: receivedBy.trim(), cod_collected_minor: codMinor ?? 0, notes }, { onSuccess: () => (notify("Delivery recorded", "success"), onDone()) })}
        >
          {pickup ? "Record pickup" : "Mark delivered"}
        </Button>
      </div>
    </div>
  );
}

function DocumentForm({ delivery, onDone }: { delivery: Delivery; onDone: () => void }) {
  const m = useDeliveryMutations(delivery.id);
  const { notify } = useToast();
  const suggested = requiredDocuments(delivery.method?.value ?? null)[0] ?? "delivery_sheet";
  const [type, setType] = useState<DeliveryDocumentType>(suggested);
  const [file, setFile] = useState<File | null>(null);
  const [notes, setNotes] = useState("");
  const tooBig = file !== null && file.size > 10 * 1024 * 1024;

  return (
    <div className="flex flex-col gap-4">
      <FormError message={apiErrorMessage(m.upload.error)} />
      <SelectField label="Document type *" options={DOCUMENT_TYPES} value={type} onChange={(e) => setType(e.target.value as DeliveryDocumentType)} />
      <label className="flex flex-col gap-1.5 text-xs font-semibold text-ink">
        File * (JPG, PNG, WebP or PDF, max 10 MB)
        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className={fileInput} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      </label>
      {tooBig && <p className="text-xs text-danger">The file must not be larger than 10 MB.</p>}
      <TextAreaField label="Notes" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button disabled={!file || tooBig} loading={m.upload.isPending} onClick={() => file && m.upload.mutate({ type, file, notes }, { onSuccess: () => (notify("Document uploaded", "success"), onDone()) })}>Upload</Button>
      </div>
    </div>
  );
}

function CompleteForm({ delivery, onDone }: { delivery: Delivery; onDone: () => void }) {
  const m = useDeliveryMutations(delivery.id);
  const { notify } = useToast();
  const [notes, setNotes] = useState("");

  return (
    <div className="flex flex-col gap-4">
      <p className="text-xs text-muted">Marks the job Completed and records you as the person who completed it. This cannot be undone.</p>
      <FormError message={apiErrorMessage(m.complete.error)} />
      <TextAreaField label="Completion notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="flex justify-end gap-2.5">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button loading={m.complete.isPending} onClick={() => m.complete.mutate(notes, { onSuccess: () => (notify("Job completed", "success"), onDone()) })}>Complete job</Button>
      </div>
    </div>
  );
}

function DeliveryDetail({ id }: { id: number }) {
  const query = useDelivery(id);
  const [modal, setModal] = useState<ModalKind | null>(null);

  if (query.isPending) return <LoadingState />;
  if (query.error) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const d = query.data;
  const a = d.abilities;
  const close = () => setModal(null);
  const blockers = Object.entries(d.completion?.blockers ?? {});
  const completed = d.production_status.is_completed;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Delivery — ${d.job_no}`}
        breadcrumb={
          <div className="flex items-center gap-1.5 text-xs text-muted">
            <Link href="/deliveries/" className="hover:text-ink">Deliveries</Link>
            <span>/</span>
            <span className="font-semibold text-ink">{d.job_no}</span>
          </div>
        }
        description={`${d.customer.name} · ${d.branch} / ${d.page.code} · ${d.product.category ?? ""} × ${d.quantity} · Due ${formatBusinessDate(d.order.due_date)}`}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Badge tone={deliveryStatusTone(d.status.value, completed)} dot>{completed ? "Completed" : d.status.label}</Badge>
            {a?.dispatch && <Button size="sm" onClick={() => setModal("dispatch")}>Dispatch</Button>}
            {a?.deliver && <Button size="sm" variant={d.status.value === "ready" ? "secondary" : "primary"} onClick={() => setModal("deliver")}>{d.status.value === "ready" ? "Customer pickup" : "Mark delivered"}</Button>}
            {a?.upload_document && <Button size="sm" variant="secondary" onClick={() => setModal("document")}>Upload document</Button>}
            {a?.complete_job && <Button size="sm" disabled={blockers.length > 0} onClick={() => setModal("complete")}>Complete job</Button>}
          </div>
        }
      />

      {!completed && d.status.value === "delivered" && blockers.length > 0 && (
        <Section title="Before the job can be completed">
          <ul className="flex flex-col gap-2 text-xs">
            {blockers.map(([key, list]) => (
              <li key={key}>
                <strong className="text-ink">{COMPLETION_GROUPS[key] ?? key}</strong>
                <ul className="ml-4 list-disc text-muted">{list.map((b) => <li key={b}>{b}</li>)}</ul>
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Section title="Job">
        <dl className="grid gap-x-6 sm:grid-cols-2">
          <Value label="Customer">{d.customer.name}{d.customer.phone ? ` · ${d.customer.phone}` : ""}</Value>
          <Value label="Address">{d.customer.address ?? "—"}</Value>
          <Value label="Product">{d.product.category ?? "—"}{d.product.material ? ` · ${d.product.material}` : ""}</Value>
          <Value label="Quantity">{d.quantity}</Value>
          <Value label="Production status">{d.production_status.label}</Value>
          <Value label="Outstanding balance">{d.balance.formatted}</Value>
          <Value label="Method">{d.method?.label ?? "Not chosen yet"}</Value>
          <Value label="Cash on delivery">{d.cod.amount.formatted}{d.cod.collected ? ` · collected ${d.cod.collected.formatted}` : ""}</Value>
          {d.dispatch?.courier_name && <Value label="Courier">{d.dispatch.courier_name}{d.dispatch.tracking_no ? ` · ${d.dispatch.tracking_no}` : ""}</Value>}
          {d.dispatched_at && <Value label="Dispatched">{d.dispatch?.by} · {formatDateTime(d.dispatched_at)}</Value>}
          {d.delivered_at && <Value label="Delivered">{d.delivery?.by} · {formatDateTime(d.delivered_at)} · received by {d.delivery?.received_by}</Value>}
          {d.completion?.completed_at && <Value label="Completed">{d.completion.completed_by} · {formatDateTime(d.completion.completed_at)}{d.completion.notes ? ` · ${d.completion.notes}` : ""}</Value>}
        </dl>
      </Section>

      <Section title="Delivery documents" description={d.method ? `Required for ${d.method.label.toLowerCase()}: ${requiredDocuments(d.method.value).map((t) => DOCUMENT_TYPES.find((x) => x.value === t)?.label).join(" or ")}.` : "The required document depends on the delivery method."}>
        {(d.documents ?? []).length === 0 ? (
          <EmptyState title="No documents uploaded" />
        ) : (
          <ul className="flex flex-col gap-2 text-xs">
            {d.documents?.map((doc) => (
              <li key={doc.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-line p-3">
                <Badge tone="neutral">{doc.type.label}</Badge>
                <a className="inline-flex items-center gap-1 text-brand hover:underline" href={doc.url} target="_blank" rel="noopener noreferrer"><DownloadIcon size={12} /> {doc.name}</a>
                <span className="text-muted">· {doc.uploaded_by} · {formatDateTime(doc.uploaded_at)}{doc.notes ? ` · ${doc.notes}` : ""}</span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Modal open={modal === "dispatch"} title="Dispatch" onClose={close}>{modal === "dispatch" && <DispatchForm delivery={d} onDone={close} />}</Modal>
      <Modal open={modal === "deliver"} title={d.status.value === "ready" ? "Customer pickup" : "Mark delivered"} onClose={close}>{modal === "deliver" && <DeliverForm delivery={d} onDone={close} />}</Modal>
      <Modal open={modal === "document"} title="Upload delivery document" onClose={close}>{modal === "document" && <DocumentForm delivery={d} onDone={close} />}</Modal>
      <Modal open={modal === "complete"} title="Complete job" onClose={close}>{modal === "complete" && <CompleteForm delivery={d} onDone={close} />}</Modal>
    </div>
  );
}

export function DeliveryScreen() {
  return <WithIdParam>{(id) => (id === null ? <EmptyState title="Delivery not found" /> : <DeliveryDetail id={id} />)}</WithIdParam>;
}
