"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api } from "@/lib/api/client";
import type { SubscriptionPayment } from "@/lib/api/types";
import { formatBDT } from "@/lib/money";
import { useSession } from "@/lib/session/SessionProvider";
import { Alert } from "@/components/ui/Alert";
import { Card, CardBody } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSpinner } from "@/components/ui/Spinner";

const POLL_MS = 3000;
const MAX_POLL_MS = 3 * 60_000;

/**
 * Shown after returning from checkout. It never trusts the URL: the payment status is
 * read from the API, which only reports success after server-side verification.
 */
export function PaymentResultView() {
  const params = useSearchParams();
  const paymentId = params.get("payment");
  const { tenantId, refresh } = useSession();
  const [startedAt] = useState(() => Date.now());

  const payment = useQuery({
    queryKey: ["subscription-payment", tenantId, paymentId],
    queryFn: () => api.get<{ data: SubscriptionPayment }>(`subscription/payments/${paymentId}`).then((r) => r.data),
    enabled: !!paymentId && !!tenantId,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      const open = !status || status === "initiated" || status === "unknown";
      return open && Date.now() - startedAt < MAX_POLL_MS ? POLL_MS : false;
    },
  });

  const applied = payment.data?.status === "completed" && payment.data.applied;
  useEffect(() => {
    if (applied) void refresh();
  }, [applied, refresh]);

  let content: React.ReactNode;
  if (!paymentId || params.get("error")) {
    content = <Alert tone="danger" title="We couldn't match this payment">If money was deducted, it will be reconciled automatically. Check your payment history or contact support.</Alert>;
  } else if (payment.isPending) {
    content = <PageSpinner label="Confirming your payment…" />;
  } else if (payment.isError) {
    content = <Alert tone="danger">{payment.error.message}</Alert>;
  } else {
    const p = payment.data;
    if (applied) {
      content = (
        <Alert tone="success" title="Payment confirmed">
          {formatBDT(p.amount_minor)} received (Trx {p.transaction_id}). Your subscription is active and full access is restored.
        </Alert>
      );
    } else if (p.status === "completed" && p.requires_review) {
      content = <Alert tone="warning" title="Payment under review">We received a payment that needs a manual check. Our team will contact you; no action is needed now.</Alert>;
    } else if (p.status === "initiated" || p.status === "unknown") {
      content = <Alert tone="info" title="Waiting for confirmation from bKash">This can take a moment. You can safely leave this page; we will keep checking and activate your subscription once bKash confirms.</Alert>;
    } else if (p.status === "cancelled") {
      content = <Alert tone="warning" title="Payment cancelled">No money was taken. You can try again any time.</Alert>;
    } else {
      content = <Alert tone="danger" title="Payment not completed">The payment was not successful. Please try again.</Alert>;
    }
  }

  return (
    <>
      <PageHeader title="Payment result" />
      <Card>
        <CardBody className="space-y-4">
          {content}
          <Link href="/app/subscription" className="inline-flex h-10 items-center rounded-lg border border-border px-4 text-sm font-medium hover:bg-canvas">
            Back to subscription
          </Link>
        </CardBody>
      </Card>
    </>
  );
}
