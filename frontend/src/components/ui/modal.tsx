"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { XIcon } from "./icons";

/** Native <dialog> modal (focus trap, Esc closes) for small forms. */
export function Modal({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: ReactNode }) {
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
      aria-labelledby="modal-title"
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      className="w-full max-w-lg rounded-2xl border border-line bg-surface p-0 text-ink shadow-xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-xs open:animate-in open:fade-in-0 open:zoom-in-95 m-auto"
    >
      {open && (
        <div className="flex flex-col p-6">
          <div className="mb-5 flex items-center justify-between border-b border-line/60 pb-3">
            <h2 id="modal-title" className="text-lg font-bold tracking-tight text-ink">
              {title}
            </h2>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close dialog"
              className="rounded-lg p-1 text-muted hover:bg-subtle hover:text-ink transition cursor-pointer"
            >
              <XIcon size={18} />
            </button>
          </div>
          <div>{children}</div>
        </div>
      )}
    </dialog>
  );
}
