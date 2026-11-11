"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useEffect, useRef } from "react";
import { api } from "@/lib/api-client";

export const notificationKeys = {
  unreadCount: ["notifications", "unread-count"] as const,
  poll: ["notifications", "poll"] as const,
};

interface PollResult {
  unread: number;
  new: { id: string; data: { title?: string; message?: string; link?: string | null } }[];
  server_time: string;
}

/**
 * Near-real-time notifications without WebSockets (shared hosting cannot run Reverb): the
 * cheap `notifications/poll` endpoint is called every 20 s with the last server time, and
 * polling pauses while the tab is hidden. New notifications refresh the inbox and the dashboard.
 */
export function useUnreadCount() {
  const since = useRef<string | null>(null);
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: notificationKeys.poll,
    queryFn: async ({ signal }) => {
      const r = await api.get<{ data: PollResult }>("notifications/poll", { since: since.current ?? undefined }, signal);
      since.current = r.data.server_time;
      return r.data;
    },
    refetchInterval: 20_000,
    refetchIntervalInBackground: false,
    staleTime: 15_000,
  });
  const fresh = query.data?.new.length ?? 0;

  useEffect(() => {
    if (fresh > 0) {
      void queryClient.invalidateQueries({ queryKey: ["notifications", "list"] });
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    }
  }, [fresh, query.data?.server_time, queryClient]);

  return { ...query, data: query.data?.unread };
}

export function NotificationBell() {
  const { data: unread = 0 } = useUnreadCount();
  const label = unread > 0 ? `Notifications, ${unread} unread` : "Notifications";

  return (
    <Link href="/notifications/" aria-label={label} title={label} className="relative inline-flex size-9 items-center justify-center rounded-md text-muted hover:bg-subtle">
      <svg aria-hidden viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth="1.8">
        <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
      </svg>
      {unread > 0 && (
        <span className="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-danger px-1 text-center text-[10px] font-semibold leading-4 text-white">{unread > 99 ? "99+" : unread}</span>
      )}
    </Link>
  );
}
