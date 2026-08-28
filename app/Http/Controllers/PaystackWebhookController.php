<?php

namespace App\Http\Controllers;

use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Handle incoming webhook requests from Paystack.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (!$this->paystackService->validateWebhookSignature($rawPayload, $signature)) {
            Log::warning('Paystack webhook received with invalid signature', [
                'ip' => $request->ip(),
                'signature' => $signature,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = json_decode($rawPayload, true);
        if (!$event || empty($event['event'])) {
            return response()->json(['message' => 'Empty or invalid event payload.'], 400);
        }

        $eventName = $event['event'];
        Log::info("Paystack webhook received: {$eventName}", [
            'reference' => $event['data']['reference'] ?? null,
        ]);

        if ($eventName === 'charge.success') {
            try {
                $result = $this->paystackService->processSuccessfulPayment($event['data']);

                Log::info('Paystack webhook charge.success processed', [
                    'reference' => $event['data']['reference'] ?? null,
                    'already_processed' => $result['already_processed'] ?? false,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error processing Paystack charge.success webhook', [
                    'reference' => $event['data']['reference'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                // Return 500 so Paystack retries if there was a temporary system failure
                return response()->json([
                    'message' => 'Error processing payment.',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
}
