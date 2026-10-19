"use client";

import type { ReactElement } from "react";
import { cloneElement } from "react";
import type { Permission } from "@/lib/api/types";
import { useWriteAccess, type WriteAccess } from "@/lib/session/SessionProvider";

const reasons: Record<NonNullable<WriteAccess["reason"]>, string> = {
  read_only: "Renew your subscription to use this action.",
  suspended: "Your company account is suspended.",
  permission: "You don't have permission for this action.",
};

/**
 * Wraps an action control. When the action isn't available, the control is either
 * hidden (permission) or disabled with an explanation (read-only / suspended), so no
 * misleading enabled buttons are shown. Server-side enforcement is independent.
 */
export function WriteGuard({ permission, children, hideWithoutPermission = true }: { permission: Permission; children: ReactElement<{ disabled?: boolean; title?: string; "aria-disabled"?: boolean }>; hideWithoutPermission?: boolean }) {
  const access = useWriteAccess(permission);

  if (access.allowed) return children;
  if (access.reason === "permission" && hideWithoutPermission) return null;

  return cloneElement(children, {
    disabled: true,
    "aria-disabled": true,
    title: reasons[access.reason ?? "permission"],
  });
}

export function writeBlockedMessage(reason: WriteAccess["reason"]): string | null {
  return reason ? reasons[reason] : null;
}
