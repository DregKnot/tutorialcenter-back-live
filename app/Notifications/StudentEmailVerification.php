<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class StudentEmailVerification extends Notification
{
    use Queueable;
    public function __construct(public string $token)
    {
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $baseUrl = rtrim(config('app.frontend_url', 'https://www.tutorialcenter.africa'), '/');
        $verifyUrl = $baseUrl . "/register/student/email/verify?email={$notifiable->email}&token={$this->token}";
            return (new MailMessage)
        ->subject('Verify Your Email Address')
        ->greeting('Hello!')
        ->line('Please verify your email address to activate your account.')
        ->action('Verify Email', $verifyUrl)
        ->line('This link will expire in 30 minutes.')
        ->line('Or use the OTP below:')
        ->line($this->token)
        ->line("Please note that if you don't verify your email within 24 hours, your account may be deleted and will require you to create a new one.")
        ->line('If you did not create an account, no further action is required.');

    }

}
