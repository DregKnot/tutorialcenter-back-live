<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Models\Guardian;
use App\Models\Staff;
use App\Models\Student;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LiveClassReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ClassSession $session,
        public ?Student $student = null
    ) {
        $this->session->loadMissing(['class.subject']);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (filter_var(trim((string) $notifiable->email), FILTER_VALIDATE_EMAIL)) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->buildPayload($notifiable);
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->buildPayload($notifiable);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable->firstname ?: 'there';
        $subjectName = $this->session->class?->subject?->name ?? ($this->session->class?->title ?? 'Live Class');
        $classTitle = $this->session->class?->title ?? $subjectName;
        $startTime = StudentNotificationService::sessionTime($this->session, 'starts_at');
        $timeStr = $startTime ? $startTime->format('g:i A') : ($this->session->starts_at ?? 'soon');
        $dateStr = $this->session->session_date ? $this->session->session_date->format('D, j M Y') : today()->format('D, j M Y');

        $baseUrl = rtrim(config('app.frontend_url', 'https://www.tutorialcenter.africa'), '/');
        $joinUrl = "{$baseUrl}/zoom/masterclass/class/{$this->session->id}";

        $mail = (new MailMessage)
            ->subject($this->subjectFor($notifiable))
            ->greeting("Hello {$name},")
            ->line($this->messageFor($notifiable))
            ->line("**Class:** {$classTitle}")
            ->line("**Subject:** {$subjectName}")
            ->line("**Date:** {$dateStr}")
            ->line("**Start Time:** {$timeStr}");

        if ($notifiable instanceof Student) {
            $mail->action('Join Live Class', $joinUrl)
                ->line('Please ensure you are in a quiet environment with a stable internet connection.');
        } elseif ($notifiable instanceof Guardian) {
            $mail->action('View Guardian Dashboard', $baseUrl . '/guardian/dashboard')
                ->line('Please encourage your ward to join on time and be prepared.');
        } else {
            // Staff / Advisor
            $mail->action('Go to Staff Portal', $baseUrl . '/staff/dashboard')
                ->line('Please monitor student attendance and participation.');
        }

        return $mail;
    }

    protected function buildPayload(object $notifiable): array
    {
        $subjectName = $this->session->class?->subject?->name ?? ($this->session->class?->title ?? 'Live Class');
        $classTitle = $this->session->class?->title ?? $subjectName;
        $studentName = $this->student ? trim($this->student->firstname . ' ' . $this->student->surname) : 'Student';
        $startTime = StudentNotificationService::sessionTime($this->session, 'starts_at');
        $baseUrl = rtrim(config('app.frontend_url', 'https://www.tutorialcenter.africa'), '/');
        $joinUrl = "{$baseUrl}/zoom/masterclass/class/{$this->session->id}";

        return [
            'type' => 'live_class_reminder',
            'title' => $this->subjectFor($notifiable),
            'message' => $this->messageFor($notifiable),
            'data' => [
                'class_session_id' => $this->session->id,
                'class_id' => $this->session->class_id,
                'subject_id' => $this->session->class?->subject_id,
                'subject_name' => $subjectName,
                'class_title' => $classTitle,
                'student_id' => $this->student?->id,
                'student_name' => $studentName,
                'session_date' => $this->session->session_date?->toDateString(),
                'starts_at' => $startTime?->toISOString() ?? (string) $this->session->starts_at,
                'ends_at' => (string) $this->session->ends_at,
                'join_url' => $joinUrl,
            ],
            'time' => now()->toISOString(),
        ];
    }

    private function subjectFor(object $notifiable): string
    {
        $classTitle = $this->session->class?->title ?? 'Live Class';
        $studentName = $this->student ? trim($this->student->firstname . ' ' . $this->student->surname) : 'Student';

        if ($notifiable instanceof Student) {
            return "Reminder: Your {$classTitle} class starts in 15 minutes";
        } elseif ($notifiable instanceof Guardian) {
            return "Reminder: {$studentName}'s {$classTitle} class starts in 15 minutes";
        } else {
            // Staff / Advisor
            return "Class Reminder: {$studentName} has {$classTitle} starting in 15 minutes";
        }
    }

    private function messageFor(object $notifiable): string
    {
        $classTitle = $this->session->class?->title ?? 'Live Class';
        $studentName = $this->student ? trim($this->student->firstname . ' ' . $this->student->surname) : 'your ward';
        $startTime = StudentNotificationService::sessionTime($this->session, 'starts_at');
        $timeStr = $startTime ? $startTime->format('g:i A') : ($this->session->starts_at ?? 'in 15 minutes');

        if ($notifiable instanceof Student) {
            return "Your live class for {$classTitle} is scheduled to start at {$timeStr} (in 15 minutes). Get ready to join!";
        } elseif ($notifiable instanceof Guardian) {
            return "This is a reminder that {$studentName}'s live class for {$classTitle} is scheduled to start at {$timeStr} (in 15 minutes).";
        } else {
            return "This is a reminder that {$studentName}'s live class for {$classTitle} is scheduled to start at {$timeStr} (in 15 minutes).";
        }
    }
}

