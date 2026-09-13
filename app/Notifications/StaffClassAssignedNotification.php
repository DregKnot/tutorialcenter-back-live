<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class StaffClassAssignedNotification extends Notification
{
    public function __construct(public array $details) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'class_assigned',
            'title' => 'New class assignment',
            'message' => $this->message(),
            'data' => $this->details,
            'time' => $this->details['assigned_at'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim($notifiable->firstname.' '.$notifiable->surname);
        $title = $this->mailText($this->details['title']);
        $role = $this->mailText(ucfirst($this->details['role']));
        $timezone = $this->mailText($this->details['timezone']);

        $mail = (new MailMessage)
            ->subject('Class assignment: '.$this->details['title'])
            ->greeting('Hello '.($this->mailText($name) ?: 'there').',')
            ->line('You have been assigned to **'.$title.'** as **'.$role.'**.');

        foreach ($this->details['schedules'] as $schedule) {
            $mail->line('**'.$this->mailText(ucfirst($schedule['day_of_week'])).':** '
                .Carbon::parse($schedule['start_time'])->format('g:i A').'–'
                .Carbon::parse($schedule['end_time'])->format('g:i A').' ('.$timezone.')');
        }

        if ($this->details['first_session_date']) {
            $first = Carbon::parse($this->details['first_session_date'])->format('j M Y');
            $last = Carbon::parse($this->details['last_session_date'] ?? $this->details['first_session_date'])->format('j M Y');
            $mail->line('**Sessions:** '.$first.($first === $last ? '' : ' – '.$last));
        } else {
            $mail->line('No upcoming sessions are currently scheduled.');
        }

        if ($this->details['status'] !== 'active') {
            $mail->line('**Status:** '.$this->mailText(ucfirst($this->details['status'])));
        }

        // Account role chooses the dashboard; the class pivot role supplies Lead/Assistant above.
        $path = match (strtolower(trim((string) $notifiable->role))) {
            'tutor' => '/staffs/tutor/master-class',
            'advisor', 'course_advisor', 'course-advisor' => '/staffs/course-advisor/master-class',
            default => '/',
        };
        $mail->action(
            $path === '/' ? 'Open staff portal' : 'View masterclass',
            'https://www.tutorialcenter.africa'.$path
        );

        return $mail->salutation('Thank you, '.config('app.name').' team');
    }

    private function mailText(string $value): string
    {
        // Keep administrator-entered names/titles from becoming Markdown formatting.
        return addcslashes(preg_replace('/\s+/u', ' ', trim($value)), '\\`*_{}[]()#+.!|>~-');
    }

    public function toSms(): string
    {
        $title = Str::limit($this->details['title'], 70);
        $first = $this->details['first_session_date'];
        $schedule = $first ? ' First session: '.$first.'.' : '';

        return 'Assigned to '.$title.' as '.$this->details['role'].'.'.$schedule.' Check your staff dashboard for times and details.';
    }

    private function message(): string
    {
        return 'You have been assigned to '.$this->details['title'].' as '.$this->details['role'].'.';
    }
}
