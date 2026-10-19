<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Reset link opens the frontend reset form; the token is sent back to the API.
 */
class ResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
