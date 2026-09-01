<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

class AssessmentNotification extends Notification
{
    protected $type;
    protected $message;
    protected $data;

    public function __construct(string $type, string $message, array $data = [])
    {
        $this->type = $type;
        $this->message = $message;
        $this->data = $data;
    }

    public function via(object $notifiable): array
    {
        $emailTypes = ['assessment_published', 'assessment_graded'];

        if (config('services.assessments.send_email', true) && in_array($this->type, $emailTypes, true)) {
            return ['database', 'mail'];
        }

        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'data' => $this->data,
            'time' => now(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = isset($notifiable->firstname)
            ? trim($notifiable->firstname . ' ' . ($notifiable->surname ?? ''))
            : null;

        $mail = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello ' . ($name ?: 'there') . '!')
            ->line($this->message);

        if ($this->type === 'assessment_published' && ! empty($this->data['due_at'])) {
            $mail->line('This assessment is due by: ' . $this->formatDate($this->data['due_at']));
        }

        if ($this->type === 'assessment_graded') {
            $score = $this->data['score'] ?? null;
            $total = $this->data['total_marks'] ?? null;

            if ($score !== null) {
                $line = 'Your score: ' . $score;
                if ($total !== null) {
                    $line .= ' out of ' . $total;
                }
                if (isset($this->data['percentage'])) {
                    $line .= ' (' . $this->data['percentage'] . '%)';
                }
                $mail->line($line . '.');
            }
        }

        if (! empty($this->data['assessment_id'])) {
            $mail->action('Open Assessment', $this->assessmentUrl());
        }

        return $mail->line('Thank you for using Tutorial Center.');
    }

    protected function subjectLine(): string
    {
        return match ($this->type) {
            'assessment_published' => 'New assessment: ' . ($this->data['title'] ?? ''),
            'assessment_graded' => 'Your assessment has been graded',
            default => 'Assessment update',
        };
    }

    protected function assessmentUrl(): string
    {
        $id = $this->data['assessment_id'] ?? null;
        $base = rtrim((string) config('app.url'), '/');

        return $id ? $base . '/assessments/' . $id : $base;
    }

    protected function formatDate($value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('D, j M Y g:i A');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}
