<?php

namespace App\Modules\Subscriptions\Http\Resources;

use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPayment */
class SubscriptionPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isPlatform = (bool) $request->user()?->isPlatformStaff() && $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'gateway' => $this->gateway,
            'environment' => $this->environment,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'transaction_id' => $this->verified_trx_id,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'applied' => $this->applied_at !== null,
            'refunded_amount_minor' => $this->refunded_amount_minor,
            'requires_review' => $this->requires_review,
            'failure_code' => $this->failure_code,
            'plan_code' => $this->whenLoaded('order', fn () => $this->order->plan_code),
            'merchant_reference' => $this->whenLoaded('order', fn () => $this->order->merchant_invoice_number),
            'created_at' => $this->created_at->toIso8601String(),
            $this->mergeWhen($isPlatform, fn () => [
                'tenant_id' => $this->tenant_id,
                'gateway_payment_id' => $this->gateway_payment_id,
                'failure_message' => $this->failure_message,
                'disputed_at' => $this->disputed_at?->toIso8601String(),
                'dispute_note' => $this->dispute_note,
                'check_attempts' => $this->check_attempts,
                'last_checked_at' => $this->last_checked_at?->toIso8601String(),
                'gateway_history' => $this->gateway_payload,
            ]),
        ];
    }
}
