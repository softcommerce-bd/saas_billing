import type { ReactNode } from "react";
import { cn } from "./cn";

export type Tone = "neutral" | "success" | "warning" | "danger" | "info" | "brand";

const tones: Record<Tone, string> = {
  neutral: "bg-canvas text-muted ring-border",
  success: "bg-success-50 text-success-700 ring-success-700/20",
  warning: "bg-warning-50 text-warning-700 ring-warning-700/20",
  danger: "bg-danger-50 text-danger-700 ring-danger-700/20",
  info: "bg-info-50 text-info-700 ring-info-700/20",
  brand: "bg-brand-50 text-brand-700 ring-brand-700/20",
};

export function Badge({ tone = "neutral", children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return (
    <span className={cn("inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset", tones[tone], className)}>
      {children}
    </span>
  );
}
