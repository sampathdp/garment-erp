import type { ReactNode } from "react";
import { ApiError } from "@/lib/api-client";
import { Button } from "./button";
import { AlertTriangleIcon, BoxesIcon, RefreshCwIcon } from "./icons";
import { Spinner } from "./spinner";

export function LoadingState({ label = "Loading…" }: { label?: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted">
      <div className="relative flex items-center justify-center">
        <div className="size-10 rounded-full border-2 border-brand/20 animate-ping absolute opacity-40" />
        <Spinner className="size-8 text-brand" />
      </div>
      <span className="text-xs font-medium tracking-wide uppercase text-muted">{label}</span>
    </div>
  );
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-center px-4 rounded-xl border border-dashed border-line bg-surface/40">
      <div className="flex size-12 items-center justify-center rounded-full bg-subtle text-muted">
        <BoxesIcon size={24} />
      </div>
      <div>
        <p className="text-sm font-semibold text-ink">{title}</p>
        {description && <p className="mt-1 max-w-sm text-xs text-muted leading-relaxed">{description}</p>}
      </div>
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}

/** Shows a safe, user-facing message. The request id lets support find the server log entry. */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const apiError = error instanceof ApiError ? error : null;
  const message = apiError?.isForbidden ? "You do not have permission to view this." : (apiError?.message ?? "Something went wrong. Please try again.");

  return (
    <div role="alert" className="flex flex-col items-center justify-center gap-3 py-16 text-center px-4 rounded-xl border border-danger/20 bg-danger-soft/40">
      <div className="flex size-12 items-center justify-center rounded-full bg-danger/10 text-danger">
        <AlertTriangleIcon size={24} />
      </div>
      <div>
        <p className="text-sm font-semibold text-danger">{message}</p>
        {apiError?.requestId && <p className="mt-1 text-xs text-muted font-mono">Reference: {apiError.requestId}</p>}
      </div>
      {onRetry && !apiError?.isForbidden && (
        <Button variant="secondary" size="sm" onClick={onRetry} className="mt-2">
          Try again
        </Button>
      )}
    </div>
  );
}
