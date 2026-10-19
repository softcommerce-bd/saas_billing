import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api/client";
import type { CatalogItem, Paginated } from "@/lib/api/types";
import { useSession } from "@/lib/session/SessionProvider";

export function useCatalogItems(filters: { search?: string; type?: string; page?: number }) {
  const { tenantId } = useSession();
  return useQuery({
    queryKey: ["catalog", tenantId, filters],
    queryFn: ({ signal }) => api.get<Paginated<CatalogItem>>("catalog-items", { ...filters, per_page: 25 }, signal),
    enabled: !!tenantId,
    placeholderData: (previous) => previous,
  });
}

export function useCatalogItem(id: string) {
  const { tenantId } = useSession();
  return useQuery({
    queryKey: ["catalog", tenantId, "detail", id],
    queryFn: () => api.get<{ data: CatalogItem }>(`catalog-items/${id}`).then((r) => r.data),
    enabled: !!tenantId,
  });
}

export function useSaveCatalogItem(id?: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      (id ? api.patch<{ data: CatalogItem }>(`catalog-items/${id}`, payload) : api.post<{ data: CatalogItem }>("catalog-items", payload)).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["catalog"] }),
  });
}

export function useArchiveCatalogItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => api.delete(`catalog-items/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["catalog"] }),
  });
}
