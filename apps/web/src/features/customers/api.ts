import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api/client";
import type { Customer, Paginated } from "@/lib/api/types";
import { useSession } from "@/lib/session/SessionProvider";

export type CustomerFilters = { search?: string; status?: string; type?: string; page?: number; per_page?: number; sort?: string };

export function useCustomers(filters: CustomerFilters) {
  const { tenantId } = useSession();
  return useQuery({
    queryKey: ["customers", tenantId, filters],
    queryFn: ({ signal }) => api.get<Paginated<Customer>>("customers", filters, signal),
    enabled: !!tenantId,
    placeholderData: (previous) => previous,
  });
}

export function useCustomer(id: string) {
  const { tenantId } = useSession();
  return useQuery({
    queryKey: ["customers", tenantId, "detail", id],
    queryFn: () => api.get<{ data: Customer }>(`customers/${id}`).then((r) => r.data),
    enabled: !!tenantId,
  });
}

export type CustomerPayload = Record<string, unknown>;

export function useSaveCustomer(id?: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CustomerPayload) =>
      (id ? api.patch<{ data: Customer }>(`customers/${id}`, payload) : api.post<{ data: Customer }>("customers", payload)).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}

export function useArchiveCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => api.delete(`customers/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["customers"] }),
  });
}
