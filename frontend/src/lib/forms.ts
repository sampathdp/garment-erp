import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { ApiError } from "./api-client";

/**
 * Maps a 422 response onto react-hook-form fields. Returns the message for errors that
 * do not belong to a known field (shown as a form-level error).
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, fields: ReadonlyArray<Path<T>>): string | null {
  if (!(error instanceof ApiError)) return "Something went wrong. Please try again.";
  if (!error.isValidation) return error.message;

  let unmatched: string | null = null;
  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    if ((fields as ReadonlyArray<string>).includes(field)) {
      setError(field as Path<T>, { type: "server", message: messages[0] });
    } else {
      unmatched ??= messages[0] ?? error.message;
    }
  }
  return unmatched;
}
