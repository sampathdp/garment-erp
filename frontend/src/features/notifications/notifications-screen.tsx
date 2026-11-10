"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/ui/form-controls";
import { BellIcon, CheckIcon, ChevronLeftIcon, ChevronRightIcon } from "@/components/ui/icons";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { api, type Paginated } from "@/lib/api-client";
import { formatDateTime } from "@/lib/format";
import { notificationKeys } from "./notification-bell";

interface AppNotification {
  id: string;
  type: string;
  data: { title?: string; message?: string; link?: string | null };
  read_at: string | null;
  created_at: string | null;
}

/** Only in-app paths are followed (the payload comes from our API, but never trust a link blindly). */
const safeLink = (link: string | null | undefined) => (link && link.startsWith("/") && !link.startsWith("//") ? link : null);

export function NotificationsScreen() {
  const [page, setPage] = useState(1);
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: ["notifications", "list", page],
    queryFn: ({ signal }) => api.get<Paginated<AppNotification>>("notifications", { page }, signal),
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  });
  const markAll = useMutation({
    mutationFn: () => api.post("notifications/mark-all-read"),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["notifications"] });
      void queryClient.invalidateQueries({ queryKey: notificationKeys.unreadCount });
    },
  });

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Notifications"
        description="Real-time alerts for urgent approvals, verified payments, and design feedback."
        actions={
          <Button variant="secondary" size="sm" icon={<CheckIcon size={14} />} loading={markAll.isPending} onClick={() => markAll.mutate()}>
            Mark all as read
          </Button>
        }
      />
      {query.isPending ? (
        <LoadingState />
      ) : query.error ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : query.data.data.length === 0 ? (
        <EmptyState title="No notifications yet" description="You're all caught up! When jobs require your attention, alerts will show here." />
      ) : (
        <div className="flex flex-col gap-4">
          <ul className="divide-y divide-line/60 rounded-2xl border border-line bg-surface shadow-xs overflow-hidden">
            {query.data.data.map((n) => {
              const href = safeLink(n.data.link);
              const isUnread = !n.read_at;
              const content = (
                <div className="flex items-start gap-3.5">
                  <div className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg ${isUnread ? "bg-brand/10 text-brand" : "bg-subtle text-muted"}`}>
                    <BellIcon size={16} />
                  </div>
                  <div className="flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-1">
                      <p className={`text-sm font-semibold ${isUnread ? "text-ink" : "text-muted"}`}>
                        {n.data.title ?? n.type}
                      </p>
                      <p className="text-[11px] text-muted">{formatDateTime(n.created_at)}</p>
                    </div>
                    {n.data.message && <p className="mt-0.5 text-xs text-muted leading-relaxed">{n.data.message}</p>}
                  </div>
                </div>
              );
              return (
                <li key={n.id} className={`p-4 transition hover:bg-subtle/50 ${isUnread ? "bg-brand-soft/20 border-l-4 border-brand" : ""}`}>
                  {href ? (
                    <Link href={href} className="block group">
                      {content}
                    </Link>
                  ) : (
                    content
                  )}
                </li>
              );
            })}
          </ul>
          {query.data.meta.last_page > 1 && (
            <div className="flex items-center justify-between pt-2">
              <span className="text-xs text-muted">
                Page {query.data.meta.page} of {query.data.meta.last_page}
              </span>
              <div className="flex gap-2">
                <Button variant="secondary" size="sm" icon={<ChevronLeftIcon size={14} />} disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  Previous
                </Button>
                <Button variant="secondary" size="sm" disabled={page >= query.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                  Next
                  <ChevronRightIcon size={14} />
                </Button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
