<?php

namespace App\Modules\Tenancy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $inviterName,
        #[\SensitiveParameter] public readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to join {$this->companyName}")
            ->line("{$this->inviterName} invited you to join {$this->companyName} on ".config('app.name').'.')
            ->action('Accept invitation', config('app.frontend_url').'/accept-invitation?token='.urlencode($this->token))
            ->line('This invitation expires in 7 days.');
    }
}
