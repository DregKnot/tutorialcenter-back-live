<?php

namespace App\Notifications;

use App\Models\Student;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentActivityNotification extends Notification
{
    protected $student;

    protected $type;

    protected $data;

    public function __construct($student, string $type, array $data = [])
    {
        $this->student = $student;
        $this->type = $type;
        $this->data = $data;
    }

    public function via($notifiable)
    {
        return ['database']; // add 'mail' later if needed
    }

    public function toArray($notifiable)
    {
        return [
            'type' => $this->type,

            'student' => [
                'id' => $this->student->id,
                'name' => $this->student->firstname.' '.$this->student->surname,
            ],

            'message' => $this->buildMessage($notifiable),
            'title' => ucwords(str_replace('_', ' ', $this->type)),

            'data' => $this->data,

            'meta' => [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],

            'time' => $this->data['occurred_at'] ?? now()->toISOString(),
        ];
    }

    // Build a user-friendly message based on the activity type
    protected function buildMessage($notifiable)
    {
        $contact = $this->student->email ?: $this->student->tel;
        $identity = trim($this->student->firstname.' '.$this->student->surname);
        $contactSuffix = $contact ? " ({$contact})" : '';

        $actor = $notifiable instanceof Student && $notifiable->id === $this->student->id
            ? 'You' : $identity;
        $subject = $this->data['subject'] ?? 'Exam';
        $class = $this->data['subject'] ?? 'the scheduled';
        $award = $this->data['award_name'] ?? 'an award';
        $assessment = $this->data['title'] ?? 'an assessment';

        return match ($this->type) {
            'exam_started' => "{$actor} started {$subject} exam practice.",
            'exam_completed' => "{$actor} completed {$subject} exam practice and scored ".($this->data['score'] ?? 0).'/'.($this->data['total_questions'] ?? 0).'.',
            'exam_abandoned' => "{$actor} did not complete {$subject} exam practice before the two-hour limit.",
            'class_joined' => "{$actor} joined {$class} class.",
            'class_left' => "{$actor} left {$class} class.",
            'class_abandoned' => "{$actor} disconnected from {$class} class.",
            'achievement_awarded' => "{$actor} earned {$award}.",
            'assessment_assigned' => 'New assessment assigned to '.($actor === 'You' ? 'you' : $actor).": {$assessment}.",
            'login' => "{$identity}{$contactSuffix} just logged in",
            'logout' => "{$identity}{$contactSuffix} just logged out",
            'forget password' => "{$identity}{$contactSuffix} requested a password reset",
            'change password' => "{$identity}{$contactSuffix} changed their password",
            'update profile' => "{$identity}{$contactSuffix} updated their profile",
            'contact change request' => "{$identity}{$contactSuffix} requested a contact change",
            'confirm contact change' => "{$identity}{$contactSuffix} confirmed a contact change",

            'attendance' => "{$identity}{$contactSuffix} attended a class",
            'assignment_submitted' => "{$identity}{$contactSuffix} submitted an assignment",
            'payment_successful' => "{$identity}{$contactSuffix} made a payment",
            'schedule_update' => "Class schedule updated for {$identity}{$contactSuffix}",
            default => "New activity from {$identity}{$contactSuffix}",
        };
    }

    // // Optional: If you want to send email notifications as well
    // public function toMail($notifiable)
    // {
    //     return (new MailMessage)
    //         ->subject('Student Activity Alert')
    //         ->line($this->buildMessage())
    //         ->line('Time: ' . now());
    // }
}
