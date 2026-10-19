import type { EntitlementState } from "@/lib/api/types";
import { Badge } from "@/components/ui/Badge";

export function SubscriptionBadge({ state }: { state: EntitlementState }) {
  if (state.mode === "suspended") return <Badge tone="danger">Suspended</Badge>;
  if (state.mode === "read_only") return <Badge tone="warning">{state.reason === "pending_payment" ? "Not activated" : "Expired · Read-only"}</Badge>;
  if (state.override_active) return <Badge tone="info">Temporary access</Badge>;
  if (state.is_trial) return <Badge tone="info">Trial</Badge>;
  return <Badge tone="success">Active</Badge>;
}
