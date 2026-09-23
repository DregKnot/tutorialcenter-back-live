<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Staff;
use App\Notifications\BankTransferNotification;
use Illuminate\Support\Facades\DB;

class BankTransferNotificationService
{
    public function send(Payment $payment, string $event): void
    {
        $payment->loadMissing('student', 'enrollment.course');
        $bank = $payment->meta['bank_transfer'] ?? [];
        // Snapshot receipt details; later edits must not change the message.
        $details = [
            'student' => trim(($payment->student?->firstname ?? '').' '.($payment->student?->surname ?? '')),
            'student_email' => $payment->student?->email,
            'reference' => $payment->gateway_reference,
            'amount' => $payment->currency.' '.number_format((float) $payment->amount, 2),
            'course' => $payment->enrollment?->course?->title ?? 'Enrollment #'.$payment->course_enrollment_id,
            'billing_cycle' => str_replace('_', ' ', $payment->billing_cycle),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'reviewed_at' => $bank['review']['at'] ?? null,
            'reason' => $bank['review']['reason'] ?? null,
            'account_name' => $bank['paid_from_account_name'] ?? null,
            'amount_paid' => $bank['amount_paid'] ?? null,
            'note' => $bank['note'] ?? null,
        ];
        if ($event === 'claimed') {
            foreach (Staff::admins()->get() as $admin) {
                $this->deliver($admin, new BankTransferNotification('admin_claim', $details));
            }
        }
        if ($payment->student) {
            $this->deliver($payment->student, new BankTransferNotification($event, $details));
        }
    }

    /**
     * Deliver mail the same way the other notification services do: after the
     * payment change commits, in-process, and never letting a failure undo it.
     */
    private function deliver(object $recipient, BankTransferNotification $notification): void
    {
        $email = trim((string) $recipient->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        DB::afterCommit(function () use ($recipient, $notification, $email) {
            try {
                $mailRecipient = clone $recipient;
                $mailRecipient->email = $email;
                $mailRecipient->notifyNow($notification, ['mail']);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
