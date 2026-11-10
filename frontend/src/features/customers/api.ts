import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, type Paginated } from "@/lib/api-client";

export interface Customer {
  id: number;
  customer_code: string;
  name: string;
  phone: string;
  whatsapp: string | null;
  other_phone: string | null;
  address: string | null;
  email?: string | null;
  notes?: string | null;
  page: { id: number; code: string; name: string } | null;
  branch: { id: number; code: string; name: string } | null;
  orders_count?: number;
  created_at: string | null;
  abilities?: { update: boolean; delete: boolean };
}

export interface CustomerFormInput {
  page_id?: number;
  branch_id?: number | null;
  name: string;
  phone: string;
  whatsapp: string;
  other_phone: string | null;
  address: string | null;
  confirm_distinct?: boolean;
}

export const customerKeys = {
  list: (params: { page?: number; search?: string; page_id?: number }) => ["customers", "list", params] as const,
  detail: (id: number) => ["customers", "detail", id] as const,
};

export function useCustomers(params: { page?: number; per_page?: number; search?: string; page_id?: number; sort?: string }, enabled = true) {
  return useQuery({
    queryKey: customerKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Customer>>("customers", { page: params.page, per_page: params.per_page, sort: params.sort, "filter[search]": params.search, "filter[page_id]": params.page_id }, signal),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useCustomer(id: number | null) {
  return useQuery({
    queryKey: customerKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<{ data: Customer }>(`customers/${id}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  });
}

export function useSaveCustomer(id: number | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CustomerFormInput) => (id === null ? api.post<{ data: Customer }>("customers", input) : api.patch<{ data: Customer }>(`customers/${id}`, input)).then((r) => r.data),
    onSuccess: (customer) => {
      queryClient.setQueryData(customerKeys.detail(customer.id), customer);
      void queryClient.invalidateQueries({ queryKey: ["customers", "list"] });
    },
  });
}

export function useDeleteCustomer(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => api.delete<void>(`customers/${id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}
