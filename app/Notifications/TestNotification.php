<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Smoke-test notification for verifying the Resend mail integration.
 *
 * Usage (Tinker or test route):
 *   Notification::route('mail', 'ryuzakikamisama@gmail.com')
 *       ->notify(new \App\Notifications\TestNotification());
 *
 * This notification intentionally does NOT use ShouldQueue so it
 * sends synchronously — you get an immediate success/failure signal
 * without needing a queue worker.
 */
class TestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[CCDI Portal] Resend Integration Test')
            ->greeting('Mail delivery confirmed.')
            ->line('This is a test email sent from the CCDI Account Portal.')
            ->line('If you received this, Resend is correctly configured and the mail pipeline is working.')
            ->line('Environment: ' . config('app.env'))
            ->line('Mailer: ' . config('mail.default'))
            ->line('From address: ' . config('mail.from.address'));
    }
}