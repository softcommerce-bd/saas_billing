import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from "react";
import { cn } from "./cn";

const control =
  "block w-full rounded-lg border border-border bg-white px-3 text-sm text-ink placeholder:text-muted/70 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 disabled:bg-canvas disabled:text-muted aria-[invalid=true]:border-danger-600";

type FieldShellProps = { id: string; label: string; error?: string; hint?: ReactNode; required?: boolean; children: ReactNode };

function FieldShell({ id, label, error, hint, required, children }: FieldShellProps) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-ink">
        {label}
        {required && (
          <span aria-hidden="true" className="text-danger-600">
            {" "}
            *
          </span>
        )}
      </label>
      {children}
      {error ? (
        <p id={`${id}-error`} className="text-sm text-danger-700" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p id={`${id}-hint`} className="text-sm text-muted">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

type Common = { label: string; error?: string; hint?: ReactNode };

export const TextField = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & Common>(function TextField(
  { label, error, hint, id, className, required, ...props },
  ref,
) {
  const generated = useId();
  const fieldId = id ?? generated;
  return (
    <FieldShell id={fieldId} label={label} error={error} hint={hint} required={required}>
      <input
        ref={ref}
        id={fieldId}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined}
        aria-required={required || undefined}
        className={cn(control, "h-10", className)}
        {...props}
      />
    </FieldShell>
  );
});

export const SelectField = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & Common>(function SelectField(
  { label, error, hint, id, className, children, required, ...props },
  ref,
) {
  const generated = useId();
  const fieldId = id ?? generated;
  return (
    <FieldShell id={fieldId} label={label} error={error} hint={hint} required={required}>
      <select
        ref={ref}
        id={fieldId}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${fieldId}-error` : undefined}
        className={cn(control, "h-10", className)}
        {...props}
      >
        {children}
      </select>
    </FieldShell>
  );
});

export const TextAreaField = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & Common>(function TextAreaField(
  { label, error, hint, id, className, required, ...props },
  ref,
) {
  const generated = useId();
  const fieldId = id ?? generated;
  return (
    <FieldShell id={fieldId} label={label} error={error} hint={hint} required={required}>
      <textarea
        ref={ref}
        id={fieldId}
        rows={3}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${fieldId}-error` : undefined}
        className={cn(control, "py-2", className)}
        {...props}
      />
    </FieldShell>
  );
});

export function CheckboxField({ label, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  const id = useId();
  return (
    <div className="flex items-center gap-2">
      <input id={id} type="checkbox" className="size-4 rounded border-border text-brand-600 focus:ring-brand-500" {...props} />
      <label htmlFor={id} className="text-sm text-ink">
        {label}
      </label>
    </div>
  );
}
