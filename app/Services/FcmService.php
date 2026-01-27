<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FcmService
{
    protected $projectId;
    protected $serviceAccountPath;

    public function __construct()
    {
        $this->serviceAccountPath = storage_path('app/firebase-service-account.json');

        if (file_exists($this->serviceAccountPath)) {
            $config = json_decode(file_get_contents($this->serviceAccountPath), true);
            $this->projectId = $config['project_id'] ?? null;
        }
    }

    /**
     * Send a notification to a specific user.
     */
    public function sendToUser(User $user, $title, $body, $data = [])
    {
        $tokens = $user->fcmTokens()->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info("No FCM tokens found for user ID: {$user->id}");
            return false;
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Send a notification to multiple tokens using FCM V1.
     */
    public function sendToTokens(array $tokens, $title, $body, $data = [])
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            Log::error("FCM V1: Failed to obtain access token.");
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $successCount = 0;

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map('strval', $data), // V1 requires data values to be strings
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ];

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                if ($response->successful()) {
                    $successCount++;
                } else {
                    Log::error("FCM V1 Error for token {$token}: " . $response->body());
                }
            } catch (\Exception $e) {
                Log::error("FCM V1 Exception: " . $e->getMessage());
            }
        }

        Log::info("FCM V1: Successfully sent to {$successCount}/" . count($tokens) . " tokens.");
        return $successCount > 0;
    }

    /**
     * Generate Google OAuth2 Access Token manually using Service Account.
     */
    protected function getAccessToken()
    {
        return Cache::remember('fcm_access_token', 3500, function () {
            if (!file_exists($this->serviceAccountPath)) {
                return null;
            }

            $config = json_decode(file_get_contents($this->serviceAccountPath), true);
            $now = time();

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $payload = json_encode([
                'iss' => $config['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]);

            $base64UrlHeader = $this->base64UrlEncode($header);
            $base64UrlPayload = $this->base64UrlEncode($payload);

            $signature = '';
            openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $config['private_key'], 'sha256WithRSAEncryption');
            $base64UrlSignature = $this->base64UrlEncode($signature);

            $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->json('access_token');
        });
    }

    protected function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
