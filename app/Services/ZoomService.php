<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Exception;

class ZoomService
{
    public function getAccessToken(): string
    {
        $cacheKey = 'zoom_access_token';
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $clientId = config('services.zoom.client_id');
        $clientSecret = config('services.zoom.client_secret');
        $accountId = config('services.zoom.account_id');

        if (!$clientId || !$clientSecret || !$accountId) {
            throw new Exception('Zoom Server-to-Server credentials are not configured.');
        }

        $response = Http::asForm()
            ->withHeaders([
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ])
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $accountId,
            ]);

        if ($response->failed()) {
            throw new Exception('Failed to retrieve Zoom access token: ' . $response->body());
        }

        $data = $response->json();
        $accessToken = $data['access_token'];
        $expiresIn = ($data['expires_in'] ?? 3599) - 60;

        Cache::put($cacheKey, $accessToken, $expiresIn);

        return $accessToken;
    }

    public function createMeeting(string $title): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $title,
                'type' => 3, // Recurring meeting with no fixed time
                'settings' => [
                    'host_video' => true,
                    'participant_video' => true,
                    'waiting_room' => true,
                    'join_before_host' => false,
                    'mute_upon_entry' => true,
                    'auto_recording' => 'cloud',
                ],
            ]);

        if ($response->failed()) {
            throw new Exception('Failed to create Zoom meeting: ' . $response->body());
        }

        return $response->json();
    }

    public function deleteMeeting(string $meetingId): bool
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->delete("https://api.zoom.us/v2/meetings/{$meetingId}");

        if ($response->failed() && $response->status() !== 404) {
            throw new Exception('Failed to delete Zoom meeting: ' . $response->body());
        }

        return true;
    }

    public function generateSignature(string $meetingNumber, int $role): string
    {
        $sdkKey = config('services.zoom.sdk_key');
        $sdkSecret = config('services.zoom.sdk_secret');

        if (!$sdkKey || !$sdkSecret) {
            throw new Exception('Zoom Meeting SDK credentials are not configured.');
        }

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $iat = time() - 30;
        $exp = $iat + 7200;

        $payload = json_encode([
            'sdkKey' => $sdkKey,
            'appKey' => $sdkKey,
            'mn' => (int) $meetingNumber,
            'role' => (int) $role,
            'iat' => $iat,
            'exp' => $exp,
            'tokenExp' => $exp
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $sdkSecret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}