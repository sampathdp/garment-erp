import Link from "next/link";
import { BoxesIcon } from "@/components/ui/icons";

export default function NotFound() {
  return (
    <main className="flex min-h-screen flex-1 flex-col items-center justify-center p-6 text-center bg-canvas">
      <div className="flex size-16 items-center justify-center rounded-2xl bg-brand/10 text-brand mb-4 shadow-sm">
        <BoxesIcon size={32} />
      </div>
      <span className="font-mono text-xs font-bold uppercase tracking-widest text-brand mb-1">Error 404</span>
      <h1 className="text-2xl font-bold tracking-tight text-ink sm:text-3xl">Page Not Found</h1>
      <p className="mt-2 max-w-sm text-xs text-muted leading-relaxed">
        The manufacturing resource or page you are trying to access does not exist or has been relocated.
      </p>
      <div className="mt-6">
        <Link
          href="/dashboard/"
          className="inline-flex h-10 items-center justify-center rounded-xl bg-brand px-5 text-xs font-semibold text-white shadow-sm hover:bg-brand-strong transition active:scale-[0.98]"
        >
          Return to Dashboard
        </Link>
      </div>
    </main>
  );
}
