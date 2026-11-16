<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentPasswordResetNotification extends Notification
{
    use Queueable;

    /**
     * Password reset token.
     */
    protected string $token;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Get the notification delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = config('app.url')
            . '/reset-password?token='
            . urlencode($this->token)
            . '&email='
            . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Reset Your QuizLanka AI Password')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('We received a request to reset your QuizLanka AI account password.')
            ->line('Click the button below to reset your password.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire for security reasons.')
            ->line('If you did not request a password reset, you can safely ignore this email.')
            ->salutation('Regards, QuizLanka AI');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
