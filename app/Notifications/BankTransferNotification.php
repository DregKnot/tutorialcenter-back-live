<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BankTransferNotification extends Notification
{
    public function __construct(public string $event, public array $details)
    {
    }

    public function via(object $notifiable): array
    {
        return filter_var(trim((string) $notifiable->email), FILTER_VALIDATE_EMAIL) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = match ($this->event) {
            'admin_claim' => 'Bank transfer awaiting verification',
            'claimed' => 'Bank transfer claim received',
            'approved' => 'Payment receipt — bank transfer approved',
            'rejected' => 'Bank transfer could not be confirmed',
        };
        $message = match ($this->event) {
            'admin_claim' => 'A student has clicked “I have paid”. Check the bank before approving this transfer.',
            'claimed' => 'We received your payment claim. It is awaiting verification. This acknowledgment is not a payment receipt.',
            'approved' => 'Your bank transfer has been confirmed and your enrollment activated. Please keep this email as your payment receipt.',
            'rejected' => 'We could not confirm your bank transfer. Check your transfer details and resubmit your claim, or contact support with your payment reference.',
        };
        $mail = (new MailMessage)->subject($title)->greeting('Hello,')->line($message);
        foreach (['student' => 'Student', 'reference' => 'Payment reference', 'amount' => 'Amount', 'course' => 'Course', 'billing_cycle' => 'Billing cycle'] as $key => $label) {
            $mail->line($label.': '.$this->safe((string) $this->details[$key]));
        }
        $mail->line('Payment method: Bank transfer');
        if ($this->event === 'approved') {
            $mail->line('Status: Paid')->line('Payment date: '.$this->safe($this->details['paid_at']))
                ->line('Approval date: '.$this->safe($this->details['reviewed_at']));
        }
        if ($this->event === 'rejected') {
            $mail->line('Reason: '.$this->safe($this->details['reason']));
        }
        if ($this->event === 'admin_claim') {
            foreach (['student_email' => 'Student email', 'account_name' => 'Sender account name', 'amount_paid' => 'Student-reported amount', 'note' => 'Student note'] as $key => $label) {
                if ($this->details[$key] !== null && $this->details[$key] !== '') {
                    $mail->line($label.': '.$this->safe((string) $this->details[$key]));
                }
            }
            $url = config('bank_transfer.admin_review_url');

            return $url ? $mail->action('Review bank transfers', $url) : $mail->line('Open the admin bank-transfer review queue and search for the payment reference.');
        }

        $baseUrl = rtrim(config('app.frontend_url', 'https://www.tutorialcenter.africa'), '/');
        return $mail->action('Open student portal', $baseUrl);
    }

    private function safe(string $value): string
    {
        return addcslashes(htmlspecialchars(preg_replace('/\s+/u', ' ', trim($value)), ENT_QUOTES, 'UTF-8'), '\\`*_{}[]()#+.!|>~-');
    }
}
