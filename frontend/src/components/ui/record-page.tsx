"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, type ReactNode } from "react";
import { useAuth } from "@/features/auth/auth-provider";
import { EmptyState, LoadingState } from "./states";

/** Reads ?id= (static export: record pages cannot use dynamic segments). */
function IdParam({ children }: { children: (id: number | null) => ReactNode }) {
  const raw = useSearchParams().get("id");
  const id = raw !== null && /^\d+$/.test(raw) ? Number(raw) : null;
  return <>{children(id)}</>;
}

export function WithIdParam({ children }: { children: (id: number | null) => ReactNode }) {
  return (
    <Suspense fallback={<LoadingState />}>
      <IdParam>{children}</IdParam>
    </Suspense>
  );
}

/**
 * Hides a screen from users without any of the permissions (UX only: the API enforces every
 * request and would answer 403/404 anyway).
 */
export function RequirePermission({ anyOf, children }: { anyOf: string[]; children: ReactNode }) {
  const { can } = useAuth();
  if (!anyOf.some((p) => can(p))) {
    return <EmptyState title="You do not have access to this screen" description="Ask an administrator if you need it." />;
  }
  return <>{children}</>;
}
