import type { ReactNode } from "react";
import { ShieldIcon, ShirtIcon } from "./icons";

/** Centered card used by the public (signed-out) screens. */
export function AuthCard({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <main className="relative flex min-h-screen flex-1 flex-col items-center justify-center p-4 bg-canvas overflow-hidden">
      {/* Background ambient decorative shapes */}
      <div className="pointer-events-none absolute -top-40 -left-40 size-96 rounded-full bg-brand/5 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-40 -right-40 size-96 rounded-full bg-brand/10 blur-3xl" />

      <div className="relative w-full max-w-md">
        {/* Brand header */}
        <div className="mb-6 flex flex-col items-center text-center">
          <div className="mb-3 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-brand to-brand-strong text-white shadow-md shadow-brand/20">
            <ShirtIcon size={24} />
          </div>
          <span className="text-xs font-bold uppercase tracking-widest text-brand">Garment ERP</span>
          <p className="text-xs text-muted">Production & Manufacturing Suite</p>
        </div>

        {/* Card */}
        <div className="rounded-2xl border border-line bg-surface p-7 shadow-xl shadow-ink/5 sm:p-8 backdrop-blur-xs">
          <div className="mb-6">
            <h1 className="text-xl font-bold tracking-tight text-ink">{title}</h1>
            {description && <p className="mt-1.5 text-xs text-muted leading-relaxed">{description}</p>}
          </div>
          {children}
        </div>

        {/* Security footer */}
        <div className="mt-6 flex items-center justify-center gap-1.5 text-[11px] text-muted">
          <ShieldIcon size={13} className="text-success" />
          <span>Authorized staff only · Session protected</span>
        </div>
      </div>
    </main>
  );
}
