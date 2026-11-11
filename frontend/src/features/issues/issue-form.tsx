"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TextField } from "@/components/ui/field";
import { FormError, SelectField, TextAreaField } from "@/components/ui/form-controls";
import { ApiError } from "@/lib/api-client";
import { GARMENT_PARTS, ISSUE_CATEGORIES, type IssueCategory, type IssueInput, issueInputError } from "./api";

const fileInput =
  "text-xs text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-subtle file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-line cursor-pointer";

export function apiErrorMessage(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const first = Object.values(error.fieldErrors)[0]?.[0];
  return first && first !== error.message ? `${error.message} ${first}` : error.message;
}

/**
 * Report a production issue: category, description, quantity affected, optional part / size
 * and image. Client-side hints only; the server enforces the quantity rules.
 */
export function IssueForm({
  sizes = [],
  defaultCategory = "damaged_pieces",
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  sizes?: string[];
  defaultCategory?: IssueCategory;
  pending: boolean;
  error: unknown;
  onSubmit: (input: IssueInput) => void;
  onCancel: () => void;
}) {
  const [input, setInput] = useState<IssueInput>({ category: defaultCategory, description: "", quantity_affected: null, part: "", size: "", image: null });
  const [touched, setTouched] = useState(false);
  const hint = issueInputError(input);
  const category = ISSUE_CATEGORIES.find((c) => c.value === input.category);

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        setTouched(true);
        if (hint === null) onSubmit(input);
      }}
    >
      <FormError message={apiErrorMessage(error)} />
      <SelectField label="Category *" options={ISSUE_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))} value={input.category} onChange={(e) => setInput((i) => ({ ...i, category: e.target.value as IssueCategory }))} />
      <TextAreaField label="Description *" maxLength={2000} value={input.description} onChange={(e) => setInput((i) => ({ ...i, description: e.target.value }))} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label={`Quantity affected${category?.needsQuantity ? " *" : ""}`}
          type="number"
          min={1}
          inputMode="numeric"
          value={input.quantity_affected ?? ""}
          onChange={(e) => setInput((i) => ({ ...i, quantity_affected: e.target.value === "" ? null : Number(e.target.value) }))}
        />
        {sizes.length > 0 && (
          <SelectField label="Size" options={[{ value: "", label: "Not size-specific" }, ...sizes.map((s) => ({ value: s, label: s }))]} value={input.size} onChange={(e) => setInput((i) => ({ ...i, size: e.target.value }))} />
        )}
      </div>
      {input.category === "missing_parts" && (
        <SelectField label="Missing part *" options={[{ value: "", label: "Choose…" }, ...GARMENT_PARTS]} value={input.part} onChange={(e) => setInput((i) => ({ ...i, part: e.target.value }))} />
      )}
      <label className="flex flex-col gap-1.5 text-xs font-semibold text-ink">
        Image (optional, JPG, PNG or WebP, max 10 MB)
        <input type="file" accept="image/jpeg,image/png,image/webp" className={fileInput} onChange={(e) => setInput((i) => ({ ...i, image: e.target.files?.[0] ?? null }))} />
      </label>
      {touched && hint && <p className="text-xs text-danger" role="alert">{hint}</p>}
      <div className="flex justify-end gap-2.5">
        <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
        <Button type="submit" loading={pending}>Report issue</Button>
      </div>
    </form>
  );
}
