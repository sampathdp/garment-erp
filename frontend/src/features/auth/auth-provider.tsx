"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { createContext, useCallback, useContext, useMemo, type ReactNode } from "react";
import { ApiError } from "@/lib/api-client";
import { authKeys, fetchCurrentUser, logout as apiLogout } from "./api";
import type { CurrentUser } from "./types";

type AuthStatus = "loading" | "authenticated" | "unauthenticated" | "error";

interface AuthContextValue {
  status: AuthStatus;
  user: CurrentUser | null;
  /** UX only (hide/show). The API enforces every permission itself. */
  can: (permission: string) => boolean;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: authKeys.me,
    queryFn: ({ signal }) => fetchCurrentUser(signal),
    staleTime: 5 * 60_000,
    retry: false,
  });

  const unauthenticated = query.error instanceof ApiError && (query.error.isUnauthenticated || query.error.code === "account_inactive");
  const status: AuthStatus = query.isPending ? "loading" : query.data ? "authenticated" : unauthenticated ? "unauthenticated" : "error";
  const user = query.data ?? null;

  const permissions = useMemo(() => new Set(user?.permissions ?? []), [user]);
  const can = useCallback((permission: string) => permissions.has(permission), [permissions]);

  const logout = useCallback(async () => {
    try {
      await apiLogout();
    } finally {
      // Drop every cached query: no data from this session may survive logout.
      queryClient.clear();
      queryClient.setQueryData(authKeys.me, null);
      // Deliberate full page load: discards every piece of in-memory state from the old session.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.assign("/login/");
    }
  }, [queryClient]);

  const value = useMemo(() => ({ status, user, can, logout }), [status, user, can, logout]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside <AuthProvider>");
  return ctx;
}
