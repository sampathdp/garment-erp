import type { ButtonHTMLAttributes, ReactNode } from "react";
import { cn } from "@/lib/cn";
import { Spinner } from "./spinner";

type Variant = "primary" | "secondary" | "danger" | "ghost" | "outline" | "subtle";
type Size = "sm" | "md" | "lg";

const variants: Record<Variant, string> = {
  primary:
    "bg-brand text-white shadow-xs hover:bg-brand-strong focus-visible:ring-2 focus-visible:ring-brand/40 active:scale-[0.98] transition-all",
  secondary:
    "border border-line bg-surface text-ink shadow-xs hover:bg-subtle hover:border-muted/30 focus-visible:ring-2 focus-visible:ring-brand/30 active:scale-[0.98] transition-all",
  outline:
    "border border-brand/30 bg-transparent text-brand hover:bg-brand/5 focus-visible:ring-2 focus-visible:ring-brand/30 active:scale-[0.98] transition-all",
  subtle:
    "bg-subtle text-ink hover:bg-line/60 focus-visible:ring-2 focus-visible:ring-brand/30 active:scale-[0.98] transition-all",
  danger:
    "bg-danger text-white shadow-xs hover:bg-danger-strong focus-visible:ring-2 focus-visible:ring-danger/40 active:scale-[0.98] transition-all",
  ghost:
    "text-muted hover:text-ink hover:bg-subtle focus-visible:ring-2 focus-visible:ring-brand/30 active:scale-[0.98] transition-all",
};

const sizes: Record<Size, string> = {
  sm: "h-8 px-3 text-xs gap-1.5 rounded-lg",
  md: "h-9 px-4 text-sm gap-2 rounded-lg",
  lg: "h-11 px-5 text-base gap-2.5 rounded-xl font-semibold",
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
}

export function Button({
  variant = "primary",
  size = "md",
  loading = false,
  disabled,
  className,
  children,
  icon,
  type = "button",
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        "inline-flex select-none items-center justify-center font-medium transition-all duration-150 cursor-pointer",
        "focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100",
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {loading ? <Spinner className="size-4 animate-spin" /> : icon ? <span className="shrink-0">{icon}</span> : null}
      {children}
    </button>
  );
}
