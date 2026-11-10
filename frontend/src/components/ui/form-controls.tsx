import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from "react";
import { cn } from "@/lib/cn";
import { AlertCircleIcon, ChevronDownIcon } from "./icons";

const controlClass = (error?: string) =>
  cn(
    "rounded-lg border bg-surface px-3 text-sm text-ink shadow-xs transition-all outline-none",
    "placeholder:text-muted/60 focus:border-brand focus:ring-2 focus:ring-brand/20 disabled:cursor-not-allowed disabled:bg-subtle disabled:text-muted",
    error ? "border-danger focus:border-danger focus:ring-danger/20" : "border-line hover:border-muted/40",
  );

function FieldShell({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-xs font-semibold tracking-wide text-ink">
        {label}
      </label>
      {children}
      {hint && !error && (
        <p id={`${id}-hint`} className="text-xs text-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="flex items-center gap-1 text-xs font-medium text-danger">
          <AlertCircleIcon size={13} className="shrink-0" />
          <span>{error}</span>
        </p>
      )}
    </div>
  );
}

const describedBy = (id: string, error?: string, hint?: string) => [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(" ") || undefined;

export interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string;
  error?: string;
  hint?: string;
  options: { value: string; label: string }[];
}

export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(function SelectField({ label, error, hint, options, className, id, ...props }, ref) {
  const generated = useId();
  const fieldId = id ?? generated;
  return (
    <FieldShell id={fieldId} label={label} error={error} hint={hint}>
      <div className="relative flex items-center">
        <select
          ref={ref}
          id={fieldId}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy(fieldId, error, hint)}
          className={cn("h-10 w-full appearance-none pr-9", controlClass(error), className)}
          {...props}
        >
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <div className="pointer-events-none absolute right-3 text-muted">
          <ChevronDownIcon size={16} />
        </div>
      </div>
    </FieldShell>
  );
});

export interface TextAreaFieldProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label: string;
  error?: string;
  hint?: string;
}

export const TextAreaField = forwardRef<HTMLTextAreaElement, TextAreaFieldProps>(function TextAreaField({ label, error, hint, className, id, ...props }, ref) {
  const generated = useId();
  const fieldId = id ?? generated;
  return (
    <FieldShell id={fieldId} label={label} error={error} hint={hint}>
      <textarea
        ref={ref}
        id={fieldId}
        rows={3}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(fieldId, error, hint)}
        className={cn("py-2.5", controlClass(error), className)}
        {...props}
      />
    </FieldShell>
  );
});

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: ReactNode;
  description?: ReactNode;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox({ label, description, className, id, ...props }, ref) {
  const generated = useId();
  const fieldId = id ?? generated;
  return (
    <div className={cn("flex items-start gap-2.5", className)}>
      <input
        ref={ref}
        id={fieldId}
        type="checkbox"
        className="mt-0.5 size-4 rounded border-line text-brand accent-brand transition focus:ring-2 focus:ring-brand/30 cursor-pointer"
        {...props}
      />
      <label htmlFor={fieldId} className="select-none text-sm font-medium text-ink cursor-pointer">
        {label}
        {description && <span className="mt-0.5 block text-xs font-normal text-muted">{description}</span>}
      </label>
    </div>
  );
});

export type BadgeTone = "neutral" | "success" | "danger" | "brand" | "warning";
const badgeTones: Record<BadgeTone, string> = {
  neutral: "bg-subtle text-muted border-line/60",
  success: "bg-success-soft text-success-strong border-success/30",
  danger: "bg-danger-soft text-danger-strong border-danger/30",
  brand: "bg-brand/10 text-brand border-brand/20",
  warning: "bg-warning-soft text-warning border-warning/30",
};

export function Badge({ tone = "neutral", dot, children, className }: { tone?: BadgeTone; dot?: boolean; children: ReactNode; className?: string }) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium tracking-tight", badgeTones[tone], className)}>
      {dot && (
        <span
          className={cn(
            "size-1.5 rounded-full shrink-0",
            tone === "success" && "bg-success",
            tone === "danger" && "bg-danger",
            tone === "brand" && "bg-brand",
            tone === "warning" && "bg-warning",
            tone === "neutral" && "bg-muted",
          )}
        />
      )}
      {children}
    </span>
  );
}

export function StatusBadge({ active }: { active: boolean }) {
  return (
    <Badge tone={active ? "success" : "neutral"} dot>
      {active ? "Active" : "Inactive"}
    </Badge>
  );
}

/** Titled panel used to group a form or a block of details on a page. */
export function Section({
  title,
  description,
  actions,
  children,
  className,
}: {
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={cn("rounded-xl border border-line bg-surface p-5 shadow-xs md:p-6 transition-all", className)}>
      <header className="mb-5 flex flex-wrap items-start justify-between gap-3 border-b border-line/60 pb-4">
        <div>
          <h2 className="text-base font-semibold text-ink tracking-tight">{title}</h2>
          {description && <p className="mt-1 text-xs text-muted leading-relaxed">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </header>
      {children}
    </section>
  );
}

export function PageHeader({
  title,
  description,
  actions,
  breadcrumb,
}: {
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
  breadcrumb?: ReactNode;
}) {
  return (
    <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        {breadcrumb && <div className="mb-1 text-xs font-medium text-muted">{breadcrumb}</div>}
        <h1 className="text-2xl font-bold tracking-tight text-ink">{title}</h1>
        {description && <p className="mt-1 text-sm text-muted">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2.5">{actions}</div>}
    </header>
  );
}

export function FormError({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <div role="alert" className="flex items-center gap-2.5 rounded-lg border border-danger/30 bg-danger-soft px-3.5 py-2.5 text-sm font-medium text-danger">
      <AlertCircleIcon size={18} className="shrink-0" />
      <span>{message}</span>
    </div>
  );
}
