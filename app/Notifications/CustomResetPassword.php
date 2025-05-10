<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPassword extends ResetPassword
{
    use Queueable;
    public function toMail($notifiable)
    {
        $url = config('app.frontend_url') . '/reset-password?' . http_build_query([
                'token' => $this->token,
                'email' => $notifiable->email
            ]);

        return (new MailMessage)
            ->subject('Password Reset Request')
            ->line('Click the button below to reset your password:')
            ->action('Reset Password', $url)
            ->line('If you did not request this, no further action is required.');
    }
}
