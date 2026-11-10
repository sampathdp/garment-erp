import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, apiRequest, buildUrl } from "@/lib/api-client";

export interface GarmentField {
  key: string;
  label: string;
  input_type: "text" | "number" | "select" | "boolean";
  is_required: boolean;
  affects_price: boolean;
  warn_on_duplicate: boolean;
  max_length: number | null;
  options: { code: string; label: string }[];
}

export interface GarmentRow {
  id: number;
  line_no: number;
  size_id: number;
  size: string | null;
  quantity: number;
  player_name: string | null;
  number: string | null;
  notes: string | null;
  values: Record<string, string>;
}

export interface RowInput {
  size_id: number | null;
  quantity: number;
  player_name?: string | null;
  number?: string | null;
  notes?: string | null;
  values: Record<string, string>;
}

export interface ReviewIssue {
  line_no: number | null;
  field: string | null;
  message: string;
}

export interface GarmentList {
  id: number;
  version: number;
  order: { id: number; order_no: string; currency: string; gross_quantity: number };
  current_version: { id: number; version_no: number; status: "draft" | "locked" | "superseded"; change_reason: string | null; created_by: string; locked_by: string | null; locked_at: string | null };
  schema: { product_type: { id: number; name: string }; sizes: { id: number; code: string; label: string }[]; fields: GarmentField[] };
  items: GarmentRow[];
  review: {
    rows: number;
    total_quantity: number;
    expected_quantity: number;
    by_size: { size_id: number; size: string; quantity: number }[];
    price_groups: { attributes: Record<string, string>; label: string; quantity: number }[];
    errors: ReviewIssue[];
    warnings: ReviewIssue[];
    can_confirm: boolean;
  };
  pricing_preview: { lines: { description: string; quantity: number; unit_price_minor: number; line_total_minor: number }[]; subtotal_minor: number; price_list: string | null; error: string | null; details?: string[] };
  history: { version_no: number; status: string; change_reason: string | null; created_by: string; locked_by: string | null; locked_at: string | null; total_quantity: number }[];
  abilities: { edit: boolean; confirm: boolean; correct: boolean };
}

export const garmentKeys = { list: (orderId: number) => ["garment-list", orderId] as const };

export function useGarmentList(orderId: number | null) {
  return useQuery({
    queryKey: garmentKeys.list(orderId ?? 0),
    queryFn: ({ signal }) => api.get<{ data: GarmentList }>(`orders/${orderId}/garment-list`, undefined, signal).then((r) => r.data),
    enabled: orderId !== null,
  });
}

export function useGarmentMutations(orderId: number) {
  const queryClient = useQueryClient();
  const set = (list: GarmentList) => queryClient.setQueryData(garmentKeys.list(orderId), list);
  return {
    addRows: useMutation({ mutationFn: ({ list, rows }: { list: GarmentList; rows: RowInput[] }) => api.post<{ data: GarmentList }>(`garment-lists/${list.id}/rows`, { version: list.version, rows }).then((r) => r.data), onSuccess: set }),
    updateRow: useMutation({
      mutationFn: ({ list, id, row }: { list: GarmentList; id: number; row: RowInput }) => api.patch<{ data: GarmentList }>(`garment-lists/${list.id}/rows/${id}`, { version: list.version, row }).then((r) => r.data),
      onSuccess: set,
    }),
    deleteRow: useMutation({
      mutationFn: ({ list, id }: { list: GarmentList; id: number }) => apiRequest<{ data: GarmentList }>(`garment-lists/${list.id}/rows/${id}`, { method: "DELETE", body: { version: list.version } }).then((r) => r.data),
      onSuccess: set,
    }),
    importCsv: useMutation({
      mutationFn: ({ list, file, mode }: { list: GarmentList; file: File; mode: "append" | "replace" }) => {
        const body = new FormData();
        body.set("version", String(list.version));
        body.set("mode", mode);
        body.set("file", file);
        return apiRequest<{ data: GarmentList }>(`garment-lists/${list.id}/import`, { method: "POST", body }).then((r) => r.data);
      },
      onSuccess: set,
    }),
    confirm: useMutation({
      mutationFn: ({ list, acknowledge }: { list: GarmentList; acknowledge: boolean }) =>
        api.post<{ data: GarmentList & { invoice_id: number; invoice_no: string } }>(`garment-lists/${list.id}/confirm`, { version: list.version, acknowledge_warnings: acknowledge }).then((r) => r.data),
      onSuccess: (data) => {
        set(data);
        void queryClient.invalidateQueries({ queryKey: ["orders"] });
      },
    }),
    correct: useMutation({ mutationFn: ({ list, reason }: { list: GarmentList; reason: string }) => api.post<{ data: GarmentList }>(`garment-lists/${list.id}/corrections`, { reason }).then((r) => r.data), onSuccess: set }),
  };
}

export const exportUrl = (listId: number) => buildUrl(`garment-lists/${listId}/export`);

/** Parses pasted spreadsheet rows (tab or comma separated) for bulk entry: Size, Qty, then the configured fields in order. */
export function parseBulkRows(text: string, sizes: GarmentList["schema"]["sizes"], fields: GarmentField[]): { rows: RowInput[]; errors: string[] } {
  const rows: RowInput[] = [];
  const errors: string[] = [];
  text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean)
    .forEach((line, index) => {
      const cells = line.includes("\t") ? line.split("\t") : line.split(",");
      const sizeText = (cells[0] ?? "").trim().toLowerCase();
      const size = sizes.find((s) => s.code.toLowerCase() === sizeText || s.label.toLowerCase() === sizeText);
      if (!size) errors.push(`Line ${index + 1}: unknown size "${cells[0] ?? ""}".`);
      const qty = Number((cells[1] ?? "1").trim() || "1");
      const row: RowInput = { size_id: size?.id ?? null, quantity: Number.isInteger(qty) && qty > 0 ? qty : 1, values: {} };
      fields.forEach((f, i) => {
        const raw = (cells[i + 2] ?? "").trim();
        if (raw === "") return;
        const value = f.input_type === "select" ? (f.options.find((o) => o.code === raw || o.label.toLowerCase() === raw.toLowerCase())?.code ?? raw) : raw;
        if (f.key === "player_name") row.player_name = value;
        else if (f.key === "number") row.number = value;
        else row.values[f.key] = value;
      });
      rows.push(row);
    });
  return { rows, errors };
}
