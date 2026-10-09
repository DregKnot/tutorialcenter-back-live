<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkSMSService
{
    protected string $baseUrl;
    protected ?string $apiToken;
    protected string $senderId;
    protected string $gateway;
    protected bool $isSandbox;

    public function __construct()
    {
        $this->isSandbox = (bool) config('services.bulksms.sandbox', true);
        
        $this->baseUrl = $this->isSandbox
            ? rtrim(config('services.bulksms.sandbox_url', 'https://www.bulksmsnigeria.com/api/sandbox/v2'), '/')
            : rtrim(config('services.bulksms.live_url', 'https://www.bulksmsnigeria.com/api/v2'), '/');

        $this->apiToken = config('services.bulksms.api_token');
        $this->senderId = config('services.bulksms.sender_id', 'TutorialCtr');
        $this->gateway = config('services.bulksms.gateway') ?? '';
    }

    /**
     * Standard HTTP client helper with Auth headers
     */
    protected function client()
    {
        $client = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
        ->withOptions([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ])
        ->timeout(15);

        // Bypass SSL verification in sandbox or local dev if cacert is missing on Windows
        if ($this->isSandbox || app()->isLocal()) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Sanitize Nigerian phone numbers (e.g. 0803... -> 234803...)
     */
    public function formatPhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleaned, '0') && strlen($cleaned) === 11) {
            return '234' . substr($cleaned, 1);
        }
        return $cleaned;
    }

    /**
     * Check if current instance is running in Sandbox mode
     */
    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }

    /**
     * Get active Base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Send Single SMS (POST /api/sandbox/v2/sms or /api/v2/sms)
    // ─────────────────────────────────────────────────────────────────────────
    public function sendSMS(string $to, string $message, ?string $senderId = null): array
    {
        if (empty($this->apiToken)) {
            throw new \Exception('BulkSMS API token is missing. Please set BULK_SMS_API_TOKEN in your .env file.');
        }

        $formattedTo = $this->formatPhoneNumber($to);
        $from = substr($senderId ?? $this->senderId, 0, 11);

        $payload = [
            'api_token' => $this->apiToken,
            'from' => $from,
            'to' => $formattedTo,
            'body' => $message,
        ];
        if (!$this->isSandbox && !empty($this->gateway)) {
            $payload['gateway'] = $this->gateway;
        }

        $response = $this->client()->post("{$this->baseUrl}/sms", $payload);

        if ($response->successful()) {
            $data = $response->json();
            Log::info('BulkSMS successfully dispatched', [
                'sandbox' => $this->isSandbox,
                'to' => $formattedTo,
                'data' => $data['data'] ?? $data,
            ]);
            return $data ?? [];
        }

        Log::error('BulkSMS dispatch failed', [
            'status' => $response->status(),
            'error' => $response->json(),
        ]);

        $errorMsg = $response->json('error.message')
            ?? $response->json('error.description')
            ?? $response->json('message')
            ?? 'Unknown provider error';

        throw new \Exception('Failed to send SMS: ' . $errorMsg);
    }

    /**
     * Convenient alias for sendSMS
     */
    public function send(string $to, string $message, ?string $senderId = null): array
    {
        return $this->sendSMS($to, $message, $senderId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bulk Helper: Send SMS to array of recipients
    // ─────────────────────────────────────────────────────────────────────────
    public function sendBulkSMS(array $recipients, string $message, ?string $senderId = null): array
    {
        $formatted = array_map(fn($num) => $this->formatPhoneNumber($num), $recipients);
        $commaList = implode(',', $formatted);

        $payload = [
            'api_token' => $this->apiToken,
            'from' => substr($senderId ?? $this->senderId, 0, 11),
            'to' => $commaList,
            'body' => $message,
        ];
        if (!$this->isSandbox && !empty($this->gateway)) {
            $payload['gateway'] = $this->gateway;
        }

        $response = $this->client()->post("{$this->baseUrl}/sms", $payload);

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $errorMsg = $response->json('error.message')
            ?? $response->json('error.description')
            ?? $response->json('message')
            ?? 'Unknown provider error';

        throw new \Exception('Bulk SMS failed: ' . $errorMsg);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Check Account Balance (GET /api/sandbox/v2/balance)
    // ─────────────────────────────────────────────────────────────────────────
    public function getBalance(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/balance");
        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 & 4. Delivery Reports (GET /api/sandbox/v2/delivery-reports)
    // ─────────────────────────────────────────────────────────────────────────
    public function getDeliveryReports(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/delivery-reports");
        return $response->json() ?? [];
    }

    public function getDeliveryReport(string $id): array
    {
        $response = $this->client()->get("{$this->baseUrl}/delivery-reports/{$id}");
        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. List Transactions (GET /api/sandbox/v2/transactions)
    // ─────────────────────────────────────────────────────────────────────────
    public function getTransactions(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/transactions");
        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. List Sender IDs (GET /api/sandbox/v2/sender-ids)
    // ─────────────────────────────────────────────────────────────────────────
    public function getSenderIds(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/sender-ids");
        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Get Account Profile (GET /api/sandbox/v2/account)
    // ─────────────────────────────────────────────────────────────────────────
    public function getAccount(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/account");
        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Send Voice SMS (POST /api/sandbox/v2/voice/create)
    // ─────────────────────────────────────────────────────────────────────────
    public function sendVoiceSMS(string $to, string $message): array
    {
        $payload = [
            'to' => $this->formatPhoneNumber($to),
            'body' => $message,
        ];
        $response = $this->client()->post("{$this->baseUrl}/voice/create", $payload);
        return $response->json() ?? [];
    }
}