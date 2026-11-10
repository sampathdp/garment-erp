import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api-client";

export interface Role {
  id: number;
  name: string;
  label: string;
  description: string | null;
  is_system: boolean;
  is_locked: boolean;
  users_count: number;
  permissions: string[];
  abilities: { update: boolean; delete: boolean };
}

export interface PermissionGroup {
  key: string;
  label: string;
  permissions: { name: string; label: string }[];
}

export interface RoleInput {
  name?: string;
  label: string;
  description: string | null;
  permissions: string[];
}

export const roleKeys = {
  list: ["roles"] as const,
  detail: (id: number) => ["roles", id] as const,
  catalogue: ["permissions"] as const,
};

export function useRoles(enabled = true) {
  return useQuery({
    queryKey: roleKeys.list,
    queryFn: ({ signal }) => api.get<{ data: Role[] }>("roles", undefined, signal).then((r) => r.data),
    staleTime: 5 * 60_000,
    enabled,
  });
}

export function useRole(id: number | null) {
  return useQuery({
    queryKey: roleKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: Role }>(`roles/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function usePermissionCatalogue() {
  return useQuery({
    queryKey: roleKeys.catalogue,
    queryFn: ({ signal }) => api.get<{ data: PermissionGroup[] }>("permissions", undefined, signal).then((r) => r.data),
    staleTime: Infinity,
  });
}

export function useSaveRole(id: number | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: RoleInput) =>
      (id === null ? api.post<{ data: Role }>("roles", input) : api.patch<{ data: Role }>(`roles/${id}`, input)).then((r) => r.data),
    onSuccess: (role) => {
      queryClient.setQueryData(roleKeys.detail(role.id), role);
      void queryClient.invalidateQueries({ queryKey: roleKeys.list });
    },
  });
}

export function useDeleteRole() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete<void>(`roles/${id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: roleKeys.list }),
  });
}
