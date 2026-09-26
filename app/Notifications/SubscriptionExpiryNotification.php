<?php

namespace App\Notifications;

use App\Models\CoursesEnrollment;
use App\Models\Guardian;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryNotification extends Notification
{
    use Queueable;

    public ?Student $student;

    /**
     * @param CoursesEnrollment $enrollment
     * @param int $intervalDays (7 for 1 week before, 3 for 3 days before, 0 for day of expiry; -3, -6, ... -30 for post-expiry)
     * @param Student|null $student
     */
    public function __construct(
        public CoursesEnrollment $enrollment,
        public int $intervalDays,
        ?Student $student = null
    ) {
        $this->student = $student ?? $enrollment->student;
    }

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
        $isGuardian = $notifiable instanceof Guardian;
        $studentName = $this->student ? trim($this->student->firstname . ' ' . $this->student->surname) : 'Student';
        $courseTitle = $this->enrollment->course?->title ?? 'Course';
        $expiresAt = $this->enrollment->end_date?->toISOString();

        return [
            'type' => $this->intervalDays <= 0 ? 'subscription_expired' : 'subscription_expiring',
            'title' => $this->subjectFor($notifiable),
            'message' => $this->messageText($notifiable),
            'data' => [
                'course_enrollment_id' => $this->enrollment->id,
                'course_id' => $this->enrollment->course_id,
                'course_title' => $courseTitle,
                'student_id' => $this->student?->id,
                'student_name' => $studentName,
                'interval_days' => $this->intervalDays,
                'is_guardian' => $isGuardian,
                'expires_at' => $expiresAt,
            ],
            'time' => now()->toISOString(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subjectFor($notifiable))
            ->greeting('Hello ' . ($notifiable->firstname ?: 'there') . ',');

        $mail->line($this->messageText($notifiable));

        if ($this->enrollment->end_date) {
            $formattedDate = Carbon::parse($this->enrollment->end_date)
                ->timezone(config('app.timezone', 'Africa/Lagos'))
                ->format('D, j M Y');
            $mail->line('**Expiration Date:** ' . $formattedDate);
        }

        $mail->line('Please renew promptly to ensure uninterrupted access to live classes, assessments, and study materials.');
        $baseUrl = rtrim(config('app.frontend_url', 'https://www.tutorialcenter.africa'), '/');
        $dashboardUrl = $notifiable instanceof Guardian
            ? $baseUrl . '/guardian/dashboard'
            : $baseUrl . '/student/dashboard';

        $mail->action('Go to Dashboard', $dashboardUrl);

        return $mail;
    }

    private function subjectFor(object $notifiable): string
    {
        $courseTitle = $this->enrollment->course?->title ?? 'Course';
        $isGuardian = $notifiable instanceof Guardian;
        $wardPrefix = $isGuardian && $this->student ? " ({$this->student->firstname}'s Course)" : '';

        if ($this->intervalDays === 7) {
            return "Reminder: {$courseTitle} Subscription Expires in 1 Week{$wardPrefix}";
        } elseif ($this->intervalDays === 3) {
            return "Urgent: {$courseTitle} Subscription Expires in 3 Days{$wardPrefix}";
        } elseif ($this->intervalDays === 0) {
            return "Notice: {$courseTitle} Subscription Expires Today{$wardPrefix}";
        } else {
            $daysAgo = abs($this->intervalDays);
            return "Overdue Notice: {$courseTitle} Subscription Expired {$daysAgo} Days Ago{$wardPrefix}";
        }
    }

    private function messageText(object $notifiable): string
    {
        $isGuardian = $notifiable instanceof Guardian;
        $studentName = $this->student ? trim($this->student->firstname . ' ' . $this->student->surname) : 'your ward';
        $courseTitle = $this->enrollment->course?->title ?? 'Course';

        if ($isGuardian) {
            if ($this->intervalDays === 7) {
                return "This is a reminder that {$studentName}'s subscription for {$courseTitle} will expire in 7 days.";
            } elseif ($this->intervalDays === 3) {
                return "This is an important reminder that {$studentName}'s subscription for {$courseTitle} will expire in 3 days.";
            } elseif ($this->intervalDays === 0) {
                return "Please be informed that {$studentName}'s subscription for {$courseTitle} expires today.";
            } else {
                $daysAgo = abs($this->intervalDays);
                return "{$studentName}'s subscription for {$courseTitle} expired {$daysAgo} days ago. Kindly renew to restore their full access.";
            }
        }

        // For Student
        if ($this->intervalDays === 7) {
            return "Your subscription for {$courseTitle} will expire in 7 days.";
        } elseif ($this->intervalDays === 3) {
            return "Your subscription for {$courseTitle} will expire in 3 days. Renew now to avoid losing access.";
        } elseif ($this->intervalDays === 0) {
            return "Your subscription for {$courseTitle} expires today. Please renew your subscription to continue learning.";
        } else {
            $daysAgo = abs($this->intervalDays);
            return "Your subscription for {$courseTitle} expired {$daysAgo} days ago. Renew now to regain access to your classes and past exams.";
        }
    }
}

