import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { ApiError } from "@/lib/api/client";

/**
 * Copies API validation errors onto form fields. Returns a message for errors that are
 * not field-specific (shown as a form-level alert).
 */
export function applyApiErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, fieldMap: Record<string, Path<T>> = {}): string | null {
  if (!(error instanceof ApiError)) return "Something went wrong. Please try again.";

  const fields = error.fieldErrors;
  const keys = Object.keys(fields);
  for (const key of keys) {
    const name = (fieldMap[key] ?? key) as Path<T>;
    setError(name, { type: "server", message: fields[key][0] });
  }

  return keys.length > 0 && error.code === "VALIDATION_FAILED" ? null : error.message;
}
