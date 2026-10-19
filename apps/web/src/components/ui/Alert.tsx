import type { ReactNode } from "react";
import { cn } from "./cn";

type Tone = "info" | "success" | "warning" | "danger";

const tones: Record<Tone, string> = {
  info: "border-info-700/20 bg-info-50 text-info-700",
  success: "border-success-700/20 bg-success-50 text-success-700",
  warning: "border-warning-700/30 bg-warning-50 text-warning-700",
  danger: "border-danger-700/20 bg-danger-50 text-danger-700",
};

export function Alert({ tone = "info", title, children, className, action }: { tone?: Tone; title?: ReactNode; children?: ReactNode; className?: string; action?: ReactNode }) {
  return (
    <div role={tone === "danger" ? "alert" : "status"} className={cn("rounded-lg border px-4 py-3 text-sm", tones[tone], className)}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          {title && <p className="font-semibold">{title}</p>}
          {children && <div className={cn(title && "mt-1")}>{children}</div>}
        </div>
        {action}
      </div>
    </div>
  );
}
