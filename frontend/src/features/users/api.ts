import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { RoleRef } from "@/features/auth/types";
import { api, type Paginated } from "@/lib/api-client";

export interface UserRow {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  last_login_at: string | null;
  deactivated_at: string | null;
  created_at: string | null;
  roles: RoleRef[];
  branches: { id: number; code: string; name: string }[];
  pages: { id: number; code: string; name: string; is_owner: boolean }[];
  abilities?: { update: boolean; administer: boolean; view_activity: boolean };
}

export interface ActivityEntry {
  id: number;
  occurred_at: string | null;
  action: string;
  actor: { id: number; name: string | null } | null;
  subject_type: string | null;
  subject_id: number | null;
  ip: string | null;
  request_id: string | null;
  changes: unknown;
}

export interface UserListParams {
  page?: number;
  sort?: string;
  search?: string;
  is_active?: "" | "1" | "0";
  role?: string;
}

export interface CreateUserInput {
  name: string;
  email: string;
  phone: string | null;
  roles: string[];
  branch_ids: number[];
  page_ids: number[];
  owner_page_ids: number[];
  password: string;
  password_confirmation: string;
}

export const userKeys = {
  all: ["users"] as const,
  list: (params: UserListParams) => ["users", "list", params] as const,
  detail: (id: number) => ["users", "detail", id] as const,
  activity: (id: number, page: number) => ["users", "activity", id, page] as const,
};

export function useUsers(params: UserListParams) {
  return useQuery({
    queryKey: userKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<UserRow>>(
        "users",
        { page: params.page, sort: params.sort, "filter[search]": params.search, "filter[is_active]": params.is_active, "filter[role]": params.role },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useUser(id: number | null) {
  return useQuery({
    queryKey: userKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: UserRow }>(`users/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useUserActivity(id: number, page: number, enabled: boolean) {
  return useQuery({
    queryKey: userKeys.activity(id, page),
    queryFn: ({ signal }) => api.get<Paginated<ActivityEntry>>(`users/${id}/activity`, { page, per_page: 20 }, signal),
    enabled,
    placeholderData: keepPreviousData,
  });
}

export function createUser(input: CreateUserInput): Promise<UserRow> {
  return api.post<{ data: UserRow }>("users", input).then((r) => r.data);
}

/** Mutations on one user; each refreshes the detail and list caches with the server's answer. */
export function useUserMutations(id: number) {
  const queryClient = useQueryClient();
  const apply = (user: UserRow) => {
    queryClient.setQueryData(userKeys.detail(id), user);
    void queryClient.invalidateQueries({ queryKey: ["users", "list"] });
    void queryClient.invalidateQueries({ queryKey: ["users", "activity", id] });
  };

  return {
    updateDetails: useMutation({
      mutationFn: (input: { name: string; email: string; phone: string | null }) => api.patch<{ data: UserRow }>(`users/${id}`, input).then((r) => r.data),
      onSuccess: apply,
    }),
    setStatus: useMutation({
      mutationFn: (input: { is_active: boolean; reason: string | null }) => api.put<{ data: UserRow }>(`users/${id}/status`, input).then((r) => r.data),
      onSuccess: apply,
    }),
    setRoles: useMutation({
      mutationFn: (roles: string[]) => api.put<{ data: UserRow }>(`users/${id}/roles`, { roles }).then((r) => r.data),
      onSuccess: apply,
    }),
    setAssignments: useMutation({
      mutationFn: (input: { branch_ids: number[]; page_ids: number[]; owner_page_ids: number[] }) => api.put<{ data: UserRow }>(`users/${id}/assignments`, input).then((r) => r.data),
      onSuccess: apply,
    }),
    sendPasswordReset: useMutation({
      mutationFn: (signOut: boolean) => api.post<void>(`users/${id}/password-reset`, { sign_out: signOut }),
      onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["users", "activity", id] }),
    }),
    setPassword: useMutation({
      mutationFn: (input: { password: string; password_confirmation: string; sign_out: boolean }) => api.put<void>(`users/${id}/password`, input),
      onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["users", "activity", id] }),
    }),
  };
}

/** Human label for an audit action code, e.g. "users.roles_changed" -> "Users: roles changed". */
export function describeAction(action: string): string {
  const [area, verb] = action.split(".", 2);
  if (!verb) return action;
  return `${area.charAt(0).toUpperCase()}${area.slice(1).replace(/_/g, " ")}: ${verb.replace(/_/g, " ")}`;
}
