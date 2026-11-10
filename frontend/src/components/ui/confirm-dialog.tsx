"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { Button } from "./button";
import { AlertTriangleIcon } from "./icons";

export interface ConfirmDialogProps {
  open: boolean;
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: "default" | "danger";
  loading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Accessible confirmation built on the native <dialog> element (focus trap, Esc to close).
 * Use for every destructive or irreversible action (cancel order, void invoice, ...).
 */
export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  tone = "default",
  loading = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const ref = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal?.();
    if (!open && dialog.open) dialog.close?.();
  }, [open]);

  return (
    <dialog
      ref={ref}
      aria-labelledby="confirm-dialog-title"
      onCancel={(e) => {
        e.preventDefault();
        if (!loading) onCancel();
      }}
      className="w-full max-w-md rounded-2xl border border-line bg-surface p-6 text-ink shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-xs open:animate-in open:fade-in-0 open:zoom-in-95 m-auto"
    >
      {open && (
        <div className="flex flex-col gap-4">
          <div className="flex items-start gap-3">
            {tone === "danger" && (
              <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger">
                <AlertTriangleIcon size={20} />
              </div>
            )}
            <div>
              <h2 id="confirm-dialog-title" className="text-base font-bold text-ink">
                {title}
              </h2>
              {description && <div className="mt-1 text-sm text-muted leading-relaxed">{description}</div>}
            </div>
          </div>
          <div className="mt-2 flex justify-end gap-2.5">
            <Button variant="secondary" onClick={onCancel} disabled={loading}>
              {cancelLabel}
            </Button>
            <Button variant={tone === "danger" ? "danger" : "primary"} loading={loading} onClick={onConfirm}>
              {confirmLabel}
            </Button>
          </div>
        </div>
      )}
    </dialog>
  );
}
