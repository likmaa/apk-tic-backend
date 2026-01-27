<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KkiapayService
{
    protected string $publicKey;
    protected string $privateKey;
    protected string $secret;
    protected string $baseUrl;

    public function __construct()
    {
        $this->publicKey = config('services.kkiapay.public_key');
        $this->privateKey = config('services.kkiapay.private_key');
        $this->secret = config('services.kkiapay.secret');
        $this->baseUrl = config('services.kkiapay.sandbox')
            ? 'https://api-sandbox.kkiapay.me'
            : 'https://api.kkiapay.me';
    }

    /**
     * Verify a transaction with KKiaPay API
     */
    public function verifyTransaction(string $transactionId): ?array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->publicKey,
                'x-private-key' => $this->privateKey,
                'x-secret-key' => $this->secret,
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/api/v1/transactions/verify", [
                        'transactionId' => $transactionId
                    ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('KKiaPay verification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'transactionId' => $transactionId
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('KKiaPay verification error', [
                'message' => $e->getMessage(),
                'transactionId' => $transactionId
            ]);
            return null;
        }
    }
}
