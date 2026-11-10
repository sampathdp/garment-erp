"use client";

import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";
import { ToastProvider } from "@/components/ui/toast";
import { AuthProvider } from "@/features/auth/auth-provider";
import { ApiError } from "@/lib/api-client";

function redirectToLoginOn401(error: unknown) {
  if (error instanceof ApiError && error.isUnauthenticated && typeof window !== "undefined" && !window.location.pathname.startsWith("/login")) {
    const next = encodeURIComponent(window.location.pathname + window.location.search);
    // Runs outside React (query cache callback), where the router is unavailable; a full load also clears stale state.
    // eslint-disable-next-line @next/next/no-location-assign-relative-destination
    window.location.assign(`/login/?next=${next}`);
  }
}

export function makeQueryClient(): QueryClient {
  return new QueryClient({
    queryCache: new QueryCache({ onError: redirectToLoginOn401 }),
    mutationCache: new MutationCache({ onError: redirectToLoginOn401 }),
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        // Never retry client errors (401/403/404/422); retry transient failures twice.
        retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
      },
    },
  });
}

export function Providers({ children }: { children: ReactNode }) {
  const [client] = useState(makeQueryClient);
  return (
    <QueryClientProvider client={client}>
      <ToastProvider>
        <AuthProvider>{children}</AuthProvider>
      </ToastProvider>
    </QueryClientProvider>
  );
}
