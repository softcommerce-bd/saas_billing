<?php

namespace App\Modules\Subscriptions\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Payment confirmation / receipt email for a verified subscription payment.
 * Carries plain values only, so the queued job never needs a tenant context to
 * restore tenant-scoped models.
 */
class SubscriptionActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $planCode,
        public readonly string $amount,
        public readonly string $transactionId,
        public readonly string $orderReference,
        public readonly string $validThrough,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Subscription payment received')
            ->greeting('Thank you for your payment')
            ->line('Package: '.ucwords(str_replace('_', '-', $this->planCode)))
            ->line('Amount: BDT '.$this->amount)
            ->line('Transaction ID: '.$this->transactionId)
            ->line('Order reference: '.$this->orderReference)
            ->line('Your subscription is valid through '.$this->validThrough.'.')
            ->action('Open your dashboard', config('app.frontend_url').'/app/subscription');
    }
}
