<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceiptNotification extends Notification
{
    use Queueable;

    public function __construct(public Payment $payment)
    {
    }

    public function via($notifiable): array
    {
        return filter_var(trim((string) $notifiable->email), FILTER_VALIDATE_EMAIL) ? ['mail'] : [];
    }

    public function toMail($notifiable): MailMessage
    {
        $this->payment->loadMissing(['student', 'enrollment.course']);
        $studentName = trim(($this->payment->student?->firstname ?? '') . ' ' . ($this->payment->student?->surname ?? ''));
        $courseTitle = $this->payment->enrollment?->course?->title ?? 'Examination Course';
        $amountFormatted = '₦' . number_format((float) $this->payment->amount, 2);
        $reference = $this->payment->gateway_reference;
        $channel = ucwords(str_replace('_', ' ', $this->payment->payment_method ?: 'Online'));
        $billingCycle = ucwords(str_replace('_', ' ', $this->payment->billing_cycle ?: 'Monthly'));

        $isGuardian = $notifiable instanceof \App\Models\Guardian;
        $greeting = $isGuardian
            ? "Hello {$notifiable->firstname},"
            : "Hello {$this->payment->student?->firstname},";

        $intro = $isGuardian
            ? "Payment of {$amountFormatted} for {$studentName}'s {$courseTitle} course ({$billingCycle}) has been confirmed and activated."
            : "Your payment of {$amountFormatted} for {$courseTitle} ({$billingCycle}) has been confirmed and your enrollment is now active.";

        $mail = (new MailMessage)
            ->subject("Payment Receipt: {$courseTitle} Enrollment Confirmed")
            ->greeting($greeting)
            ->line($intro)
            ->line("Payment Reference: {$reference}")
            ->line("Amount Paid: {$amountFormatted}")
            ->line("Payment Method: {$channel}")
            ->line("Billing Cycle: {$billingCycle}")
            ->line("Date: " . ($this->payment->paid_at ? $this->payment->paid_at->format('M d, Y h:i A') : now()->format('M d, Y h:i A')));

        $dashboardUrl = $isGuardian
            ? config('app.frontend_url', 'https://www.tutorialcenter.africa') . '/guardian/login'
            : config('app.frontend_url', 'https://www.tutorialcenter.africa') . '/login';

        return $mail->action('Access Dashboard', $dashboardUrl)
            ->line('Thank you for choosing Tutorial Center!')
            ->line('If you have any questions or require assistance, our support team is always here to help.');
    }
}
