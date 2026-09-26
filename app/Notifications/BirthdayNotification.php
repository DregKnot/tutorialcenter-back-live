<?php

namespace App\Notifications;

use App\Models\Guardian;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $userRole // 'student', 'guardian', or 'staff'
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (filter_var(trim((string) $notifiable->email), FILTER_VALIDATE_EMAIL)) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'birthday_greeting',
            'title' => $this->subjectFor($notifiable),
            'message' => $this->messageFor($notifiable),
            'data' => [
                'user_role' => $this->userRole,
                'recipient_id' => $notifiable->id,
                'name' => trim(($notifiable->firstname ?? '') . ' ' . ($notifiable->surname ?? '')),
            ],
            'time' => now()->toISOString(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable->firstname ?: 'there';

        $mail = (new MailMessage)
            ->subject($this->subjectFor($notifiable))
            ->greeting("Happy Birthday, {$name}! 🎉")
            ->line($this->messageFor($notifiable));

        $baseUrl = rtrim(config('app.frontend_url', 'https://www.tutorialcenter.africa'), '/');
        if ($this->userRole === 'student') {
            $mail->action('Go to Learning Portal', $baseUrl . '/student/dashboard');
        } elseif ($this->userRole === 'guardian') {
            $mail->action('Visit Guardian Dashboard', $baseUrl . '/guardian/dashboard');
        } else {
            $mail->action('Go to Staff Portal', $baseUrl . '/staff/dashboard');
        }

        $mail->line('Wishing you a fantastic day and a wonderful year ahead filled with joy and success!');

        return $mail;
    }

    private function subjectFor(object $notifiable): string
    {
        $name = $notifiable->firstname ?: '';
        return match ($this->userRole) {
            'student' => $name ? "Happy Birthday, {$name}! 🎂 Wishing You Academic Excellence!" : "Happy Birthday! 🎂 Wishing You Academic Excellence!",
            'guardian' => "Happy Birthday from Tutorial Center! 🌟",
            'staff' => $name ? "Happy Birthday, {$name}! 🎈 Thank You for Being an Amazing Team Member!" : "Happy Birthday from Tutorial Center! 🎈",
            default => "Happy Birthday from Tutorial Center! 🎉",
        };
    }

    private function messageFor(object $notifiable): string
    {
        return match ($this->userRole) {
            'student' => 'Today we celebrate you! On this special day, the Tutorial Center team wishes you joy, great wisdom, and remarkable success in all your academic endeavors. Keep shining and reaching for greatness!',
            'guardian' => 'On your special day, we celebrate and appreciate you! Thank you for being a wonderful support and partner in your ward\'s educational journey. We wish you good health, prosperity, and joy all year round.',
            'staff' => 'Happy Birthday! We deeply appreciate your hard work, dedication, and passion in transforming students\' lives every day. We hope your year ahead is filled with great milestones, health, and happiness!',
            default => 'Warmest birthday wishes from all of us at Tutorial Center! May your day be filled with celebration and happiness.',
        };
    }
}

