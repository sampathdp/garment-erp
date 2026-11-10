import { cn } from "@/lib/cn";

export function Spinner({ className, label }: { className?: string; label?: string }) {
  return (
    <span role="status" aria-label={label ?? "Loading"} className={cn("inline-block size-5 animate-spin rounded-full border-2 border-current border-r-transparent", className)} />
  );
}
