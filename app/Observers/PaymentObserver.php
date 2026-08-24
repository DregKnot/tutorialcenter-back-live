<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\ExamPreparationAchievementService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

class PaymentObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected ExamPreparationAchievementService $achievementService
    ) {}

    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        if ($payment->status === 'successful') {
            $this->evaluate($payment);
        }
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        if (
            $payment->wasChanged('status')
            && $payment->status === 'successful'
        ) {
            $this->evaluate($payment);
        }
    }

    /**
     * Handle the Payment "deleted" event.
     */
    public function deleted(Payment $payment): void {}

    /**
     * Handle the Payment "restored" event.
     */
    public function restored(Payment $payment): void {}

    /**
     * Handle the Payment "force deleted" event.
     */
    public function forceDeleted(Payment $payment): void {}

    private function evaluate(Payment $payment): void
    {
        try {
            $this->achievementService->evaluatePayment($payment);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
