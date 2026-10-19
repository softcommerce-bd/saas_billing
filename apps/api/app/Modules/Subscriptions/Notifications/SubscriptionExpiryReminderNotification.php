<?php

namespace App\Modules\Subscriptions\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Platform -> tenant owner renewal reminder. Separate from the tenant's own
 * customer invoice reminders.
 */
class SubscriptionExpiryReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly string $companyName,
        public readonly CarbonImmutable $validThrough,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->validThrough->format('j F Y');
        $renewUrl = config('app.frontend_url').'/app/subscription';

        $message = (new MailMessage)->action('Renew subscription', $renewUrl);

        return match ($this->kind) {
            'expired', 'expired_3d' => $message
                ->subject("Your {$this->companyName} subscription has expired")
                ->line("Your subscription ended after {$date}. Your business data is safe and available in read-only mode.")
                ->line('Renew to continue creating invoices, recording payments and managing collections.'),
            default => $message
                ->subject("Your {$this->companyName} subscription ends on {$date}")
                ->line("Your subscription is valid through {$date}.")
                ->line('Renew before it ends to avoid interruption. After expiry your account becomes read-only.'),
        };
    }
}
