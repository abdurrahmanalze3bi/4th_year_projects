<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPassword extends ResetPassword
{
    use Queueable;
    // app/Notifications/CustomResetPassword.php
    public function toMail($notifiable)
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset()
        ]));

        return (new MailMessage)
            ->subject('Reset Password Notification')
            ->line('You are receiving this email because we requested a password reset.')
            ->action('Reset Password', $url)
            ->line('This link expires in 60 minutes.');
    }
}
