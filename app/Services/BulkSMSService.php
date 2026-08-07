<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkSMSService
{
    protected $baseUrl;
    protected $apiToken;
    protected $senderId;

    // public function __construct()
    // {
    //     $this->baseUrl = config('services.bulksms.base_url');
    //     $this->apiToken = config('services.bulksms.api_token');
    //     $this->senderId = config('services.bulksms.sender_id');
    // }

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.bulksms.base_url'),
            '/'
        );

        $this->apiToken = config('services.bulksms.api_token');

        $this->senderId = config('services.bulksms.sender_id');
    }

    /**
     * Send SMS to a single recipient
     */
    public function sendSMS(string $to, string $message, ?string $senderId = null): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->post($this->baseUrl . '/sms', [
                'from' => $senderId ?? $this->senderId,
                'to' => $to,
                'body' => $message,
            ]);

        if ($response->successful()) {
            $data = $response->json();

            Log::info('SMS sent successfully', [
                'message_id' => $data['data']['id'] ?? null,
                'cost' => $data['data']['cost'] ?? null,
            ]);

            return $data ?? [];
        }

        // Handle error
        Log::error('SMS sending failed', [
            'status' => $response->status(),
            'error' => $response->json(),
        ]);

        throw new \Exception('Failed to send SMS: ' . $response->json('message', 'Unknown error'));
    }

    /**
     * Send SMS to multiple recipients
     */
    public function sendBulkSMS(array $recipients, string $message, ?string $senderId = null): array
    {
        $results = [
            'total' => count($recipients),
            'successful' => 0,
            'failed' => 0,
            'results' => [],
        ];

        foreach ($recipients as $recipient) {
            try {
                $result = $this->sendSMS($recipient, $message, $senderId);
                $results['successful']++;
                $results['results'][] = [
                    'recipient' => $recipient,
                    'status' => 'success',
                    'data' => $result,
                ];
            } catch (\Exception $e) {
                $results['failed']++;
                $results['results'][] = [
                    'recipient' => $recipient,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}

// Configuration in config/services.php
// 'bulksms' => [
//     'base_url' => env('BULK_SMS_BASE_URL', 'https://www.bulksmsnigeria.com/api'),
//     'api_token' => env('BULK_SMS_API_TOKEN'),
//     'sender_id' => env('BULK_SMS_SENDER_ID'),
// ],

// // Usage Example
// use App\Services\BulkSMSService;

// $smsService = new BulkSMSService();

// // Send single SMS
// $result = $smsService->sendSMS(
//     to: '+2348029606405',
//     message: 'Hello from Laravel!'
// );

// // Send bulk SMS
// $results = $smsService->sendBulkSMS(
//     recipients: ['+2348029606405', '+2348087654321'],
//     message: 'Bulk message from Laravel'
// );

// echo "Sent {$results['successful']} of {$results['total']} messages";
