"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { api } from "@/lib/api-client";

export const notificationKeys = {
  unreadCount: ["notifications", "unread-count"] as const,
};

/**
 * Shared hosting cannot run WebSockets, so the unread count is polled every 30 s
 * and polling pauses while the tab is hidden (refetchIntervalInBackground: false).
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: notificationKeys.unreadCount,
    queryFn: ({ signal }) => api.get<{ data: { unread: number } }>("notifications/unread-count", undefined, signal).then((r) => r.data.unread),
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    staleTime: 25_000,
  });
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
