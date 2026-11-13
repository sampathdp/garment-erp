"use client";

import Link from "next/link";
import { useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { cn } from "@/lib/cn";
import { ChevronDownIcon, MenuIcon } from "./icons";

export interface ActionMenuItem {
  key: string;
  label: string;
  description?: string;
  icon?: ReactNode;
  href?: string;
  onClick?: () => void;
  tone?: "default" | "brand" | "success" | "danger";
  disabled?: boolean;
}

export interface ActionMenuProps {
  items: ActionMenuItem[];
  triggerLabel?: string;
  triggerIcon?: ReactNode;
  align?: "left" | "right";
  className?: string;
  size?: "sm" | "md";
}

const noopSubscribe = () => () => {};

export function ActionMenu({
  items,
  triggerLabel = "Actions",
  triggerIcon = <MenuIcon size={14} />,
  align = "right",
  className,
  size = "sm",
}: ActionMenuProps) {
  const [open, setOpen] = useState(false);
  const [coords, setCoords] = useState<{ top: number; left?: number; right?: number; openUp?: boolean } | null>(null);
  // false while prerendering the static export, true in the browser: the portal needs document.body.
  const mounted = useSyncExternalStore(noopSubscribe, () => true, () => false);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const updatePosition = () => {
    if (!buttonRef.current) return;
    const rect = buttonRef.current.getBoundingClientRect();
    const menuEstimatedHeight = items.length * 48 + 16;
    const spaceBelow = window.innerHeight - rect.bottom;
    const openUp = spaceBelow < menuEstimatedHeight && rect.top > menuEstimatedHeight;

    if (align === "right") {
      setCoords({
        top: openUp ? rect.top - 6 : rect.bottom + 6,
        right: window.innerWidth - rect.right,
        openUp,
      });
    } else {
      setCoords({
        top: openUp ? rect.top - 6 : rect.bottom + 6,
        left: rect.left,
        openUp,
      });
    }
  };

  const handleToggle = () => {
    if (!open) {
      updatePosition();
      setOpen(true);
    } else {
      setOpen(false);
    }
  };

  // Close on outside click or escape
  useEffect(() => {
    if (!open) return;

    const handleClickOutside = (e: MouseEvent) => {
      if (
        buttonRef.current?.contains(e.target as Node) ||
        menuRef.current?.contains(e.target as Node)
      ) {
        return;
      }
      setOpen(false);
    };

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };

    const handleScrollOrResize = () => {
      updatePosition();
    };

    document.addEventListener("mousedown", handleClickOutside);
    document.addEventListener("keydown", handleKeyDown);
    window.addEventListener("scroll", handleScrollOrResize, true);
    window.addEventListener("resize", handleScrollOrResize);

    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
      document.removeEventListener("keydown", handleKeyDown);
      window.removeEventListener("scroll", handleScrollOrResize, true);
      window.removeEventListener("resize", handleScrollOrResize);
    };
  }, [open, items.length, align]);

  return (
    <div className={cn("relative inline-block text-left", className)}>
      <button
        ref={buttonRef}
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={handleToggle}
        className={cn(
          "inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface font-semibold text-ink shadow-xs transition cursor-pointer select-none",
          "hover:border-brand/40 hover:bg-subtle hover:text-brand",
          open && "border-brand/60 bg-subtle text-brand ring-2 ring-brand/10",
          size === "sm" ? "px-2.5 py-1.5 text-xs" : "px-3 py-2 text-sm",
        )}
      >
        {triggerIcon}
        {triggerLabel && <span>{triggerLabel}</span>}
        <ChevronDownIcon size={12} className={cn("transition-transform duration-150 text-muted", open && "rotate-180")} />
      </button>

      {mounted &&
        open &&
        coords &&
        createPortal(
          <div
            ref={menuRef}
            style={{
              position: "fixed",
              top: coords.openUp ? undefined : `${coords.top}px`,
              bottom: coords.openUp ? `${window.innerHeight - coords.top}px` : undefined,
              left: coords.left !== undefined ? `${coords.left}px` : undefined,
              right: coords.right !== undefined ? `${coords.right}px` : undefined,
              zIndex: 9999,
            }}
            className={cn(
              "w-64 rounded-xl border border-line bg-surface p-1.5 text-ink shadow-xl ring-1 ring-black/5 dark:ring-white/10",
              "animate-in fade-in-0 duration-100",
              coords.openUp ? "origin-bottom-right zoom-in-95" : "origin-top-right zoom-in-95",
            )}
            role="menu"
            aria-orientation="vertical"
          >
            <div className="flex flex-col gap-0.5">
              {items.map((item) => {
                const itemClass = cn(
                  "group flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition select-none cursor-pointer",
                  "hover:bg-subtle",
                  item.disabled && "opacity-50 cursor-not-allowed pointer-events-none",
                  item.tone === "brand" && "hover:text-brand",
                  item.tone === "danger" && "hover:text-danger",
                  item.tone === "success" && "hover:text-emerald-500",
                );

                const content = (
                  <>
                    <div
                      className={cn(
                        "mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-subtle/80 text-muted transition",
                        "group-hover:bg-brand/10 group-hover:text-brand",
                        item.tone === "success" && "group-hover:bg-emerald-500/10 group-hover:text-emerald-500",
                        item.tone === "danger" && "group-hover:bg-danger/10 group-hover:text-danger",
                      )}
                    >
                      {item.icon}
                    </div>
                    <div className="flex min-w-0 flex-col">
                      <span className="text-xs font-semibold text-ink group-hover:text-inherit">
                        {item.label}
                      </span>
                      {item.description && (
                        <span className="text-[11px] text-muted leading-tight truncate">
                          {item.description}
                        </span>
                      )}
                    </div>
                  </>
                );

                if (item.href) {
                  return (
                    <Link
                      key={item.key}
                      href={item.href}
                      onClick={() => setOpen(false)}
                      className={itemClass}
                      role="menuitem"
                    >
                      {content}
                    </Link>
                  );
                }

                return (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => {
                      setOpen(false);
                      item.onClick?.();
                    }}
                    disabled={item.disabled}
                    className={itemClass}
                    role="menuitem"
                  >
                    {content}
                  </button>
                );
              })}
            </div>
          </div>,
          document.body,
        )}
    </div>
  );
}

