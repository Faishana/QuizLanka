<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentOtpNotification extends Notification
{
    use Queueable;

    protected string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('QuizLanka AI - Password Reset OTP')
            ->greeting('Hello!')
            ->line('We received a request to reset your QuizLanka AI password.')
            ->line('Your password reset OTP is:')
            ->line($this->otp)
            ->line('This OTP will expire in 10 minutes.')
            ->line('If you did not request a password reset, you can safely ignore this email.')
            ->salutation('Regards, QuizLanka AI');
    }
}
