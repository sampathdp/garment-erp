import { forwardRef, useId, type InputHTMLAttributes, type ReactNode } from "react";
import { cn } from "@/lib/cn";
import { AlertCircleIcon } from "./icons";

export interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string;
  hint?: string;
  icon?: ReactNode;
}

/** Labelled input with accessible error/hint wiring. Works with react-hook-form's register(). */
export const TextField = forwardRef<HTMLInputElement, TextFieldProps>(function TextField(
  { label, error, hint, icon, className, id, ...props },
  ref,
) {
  const generated = useId();
  const inputId = id ?? generated;
  const describedBy = [error ? `${inputId}-error` : null, hint ? `${inputId}-hint` : null].filter(Boolean).join(" ") || undefined;

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={inputId} className="text-xs font-semibold tracking-wide text-ink">
        {label}
      </label>
      <div className="relative flex items-center">
        {icon && <div className="pointer-events-none absolute left-3 text-muted">{icon}</div>}
        <input
          ref={ref}
          id={inputId}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={cn(
            "h-10 w-full rounded-lg border bg-surface px-3 text-sm text-ink transition-all shadow-xs",
            "outline-none placeholder:text-muted/60 focus:border-brand focus:ring-2 focus:ring-brand/20 disabled:cursor-not-allowed disabled:bg-subtle disabled:text-muted",
            icon ? "pl-9" : undefined,
            error ? "border-danger focus:border-danger focus:ring-danger/20" : "border-line hover:border-muted/40",
            className,
          )}
          {...props}
        />
      </div>
      {hint && !error && (
        <p id={`${inputId}-hint`} className="text-xs text-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${inputId}-error`} role="alert" className="flex items-center gap-1 text-xs font-medium text-danger">
          <AlertCircleIcon size={13} className="shrink-0" />
          <span>{error}</span>
        </p>
      )}
    </div>
  );
});
