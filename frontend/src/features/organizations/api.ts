import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, type Paginated } from "@/lib/api-client";

export interface Branch {
  id: number;
  code: string;
  name: string;
  address: string | null;
  phone: string | null;
  is_active: boolean;
  members_count?: number;
  created_at: string | null;
  abilities?: { update: boolean };
}

export interface Page {
  id: number;
  code: string;
  name: string;
  platform: string;
  external_url: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  address: string | null;
  pricing: { currency: string; default_advance_percent: number; default_delivery_fee_minor: number };
  invoice_prefix: string;
  invoice_header_name: string;
  invoice_address?: string | null;
  invoice_phone?: string | null;
  is_active: boolean;
  owners?: { id: number; name: string }[];
  members_count?: number;
  created_at: string | null;
  abilities?: { update: boolean; manage_members: boolean };
}

export interface PageMember {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  is_owner: boolean;
  roles: { name: string; label: string }[];
}

export interface ListParams {
  page?: number;
  per_page?: number;
  sort?: string;
  search?: string;
}

export interface BranchInput {
  code?: string;
  name: string;
  address: string | null;
  phone: string | null;
  is_active: boolean;
}

export interface PageInput {
  code?: string;
  invoice_prefix?: string;
  name: string;
  platform: string;
  external_url: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  address: string | null;
  default_advance_percent: number;
  default_delivery_fee_minor: number;
  invoice_header_name: string;
  invoice_address: string | null;
  invoice_phone: string | null;
  is_active: boolean;
}

export const PAGE_PLATFORMS = [
  { value: "facebook", label: "Facebook" },
  { value: "instagram", label: "Instagram" },
  { value: "website", label: "Website" },
  { value: "walk_in", label: "Walk-in" },
  { value: "other", label: "Other" },
];

export const organizationKeys = {
  branches: (params: ListParams) => ["branches", params] as const,
  pages: (params: ListParams) => ["pages", "list", params] as const,
  page: (id: number) => ["pages", "detail", id] as const,
  members: (id: number) => ["pages", "members", id] as const,
};

const listQuery = (params: ListParams) => ({ page: params.page, per_page: params.per_page, sort: params.sort, "filter[search]": params.search });

export function useBranches(params: ListParams, enabled = true) {
  return useQuery({
    queryKey: organizationKeys.branches(params),
    queryFn: ({ signal }) => api.get<Paginated<Branch>>("branches", listQuery(params), signal),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function usePages(params: ListParams, enabled = true) {
  return useQuery({
    queryKey: organizationKeys.pages(params),
    queryFn: ({ signal }) => api.get<Paginated<Page>>("pages", listQuery(params), signal),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function usePage(id: number | null) {
  return useQuery({
    queryKey: organizationKeys.page(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: Page }>(`pages/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function usePageMembers(id: number, enabled: boolean) {
  return useQuery({
    queryKey: organizationKeys.members(id),
    queryFn: ({ signal }) => api.get<{ data: PageMember[] }>(`pages/${id}/members`, undefined, signal).then((r) => r.data),
    enabled,
  });
}

export function useSaveBranch(id: number | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: BranchInput) => (id === null ? api.post<{ data: Branch }>("branches", input) : api.patch<{ data: Branch }>(`branches/${id}`, input)).then((r) => r.data),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["branches"] }),
  });
}

export function useSavePage(id: number | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PageInput) => (id === null ? api.post<{ data: Page }>("pages", input) : api.patch<{ data: Page }>(`pages/${id}`, input)).then((r) => r.data),
    onSuccess: (page) => {
      queryClient.setQueryData(organizationKeys.page(page.id), page);
      void queryClient.invalidateQueries({ queryKey: ["pages", "list"] });
    },
  });
}

export function useSavePageMembers(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (members: { user_id: number; is_owner: boolean }[]) => api.put<{ data: PageMember[] }>(`pages/${id}/members`, { members }).then((r) => r.data),
    onSuccess: (members) => {
      queryClient.setQueryData(organizationKeys.members(id), members);
      void queryClient.invalidateQueries({ queryKey: ["pages"] });
      void queryClient.invalidateQueries({ queryKey: ["users"] });
    },
  });
}
