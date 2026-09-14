<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentLearningNotification extends Notification
{
    public function __construct(public string $type, public string $message, public array $data = []) {}

    public function via(object $notifiable): array
    {
        return $this->type !== 'class_recording_available' && filter_var(trim((string) $notifiable->email), FILTER_VALIDATE_EMAIL)
            ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $message = $this->message;
        if (! empty($this->data['expires_at'])) {
            $message .= ' Expiry: '.$this->date($this->data['expires_at']).'.';
        }
        return ['type' => $this->type, 'title' => $this->title(), 'message' => $message, 'data' => $this->data, 'time' => now()->toISOString()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())
            ->greeting('Hello '.$this->safe($notifiable->firstname ?: 'there').',')
            ->line($this->safe($this->message));
        if ($this->type === 'class_sessions_created' && ! empty($this->data['first_session_at'])) {
            $single = ($this->data['session_count'] ?? 1) === 1;
            $mail->line('**'.($single ? 'Class starts' : 'First class').':** '.$this->date($this->data['first_session_at']));
            if (! $single) {
                $times = $this->data['schedule_times'] ?? [];
                if ($times) {
                    $mail->line('**Class days and times:** '.$this->safe(implode('; ', array_slice($times, 0, 4)))
                        .' ('.$this->safe(config('app.timezone', 'UTC')).').');
                }
                if (! empty($this->data['last_session_at'])) {
                    $last = Carbon::parse($this->data['last_session_at'])->timezone(config('app.timezone', 'UTC'))->format('j M Y');
                    $mail->line('**Last class in this schedule:** '.$last.'.');
                }
            }
            $mail->line('Sign in to your student dashboard to see each class date and join when it starts.');
        }
        if (! empty($this->data['expires_at'])) {
            $mail->line('Expires: '.$this->date($this->data['expires_at']));
        }
        if ($this->type === 'class_sessions_created') {
            return $mail->action('View class schedule', 'https://www.tutorialcenter.africa/student/class-schedule');
        }

        return $mail->action('Open student portal', 'https://www.tutorialcenter.africa');
    }

    private function title(): string
    {
        return match ($this->type) {
            'class_sessions_created' => 'New live '.($this->data['subject_name'] ?? 'subject').' '.(($this->data['session_count'] ?? 1) === 1 ? 'class' : 'classes'),
            'class_recording_available' => 'Class recording available',
            'subscription_expiring' => 'Subscription expiry reminder',
            default => 'Learning update',
        };
    }

    private function date(string $date): string
    {
        return Carbon::parse($date)->timezone(config('app.timezone', 'UTC'))->format('D, j M Y, g:i A T');
    }

    private function safe(string $value): string
    {
        return addcslashes(preg_replace('/\s+/u', ' ', trim($value)), '\\`*_{}[]()#+.!|>~-');
    }
}
