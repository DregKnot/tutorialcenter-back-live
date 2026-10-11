<?php

namespace App\Console\Commands;

use App\Services\PaystackService;
use Illuminate\Console\Command;

class VerifyPaystackPaymentCommand extends Command
{
    protected $signature = 'paystack:verify {reference : The Paystack transaction reference}';
    protected $description = 'Manually verify and settle a Paystack payment reference directly from terminal';

    public function handle(PaystackService $paystackService): int
    {
        $reference = trim($this->argument('reference'));
        $this->info("Verifying reference: {$reference} with Paystack API...");

        $verifyResult = $paystackService->verifyTransaction($reference);
        if (!$verifyResult['status'] || empty($verifyResult['data'])) {
            $this->error("Verification failed on Paystack: " . ($verifyResult['message'] ?? 'Unknown error'));
            return self::FAILURE;
        }

        try {
            $processResult = $paystackService->processSuccessfulPayment($verifyResult['data']);
            $this->info("Payment successfully processed and courses activated!");
            if (!empty($processResult['payments'])) {
                foreach ($processResult['payments'] as $p) {
                    $this->line("- Payment ID: {$p->id}, Student ID: {$p->student_id}, Amount: {$p->amount}, Status: {$p->status}");
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Error processing payment: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}