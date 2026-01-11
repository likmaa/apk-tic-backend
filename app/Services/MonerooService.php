<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class MonerooService
{
    protected $baseUrl;
    protected $publicKey;
    protected $secretKey;

    public function __construct()
    {
        // Use production URL if live, or sandbox if test
        // Default to sandbox for safety unless specified
        $this->baseUrl = 'https://api.moneroo.io/v1';

        $this->publicKey = env('MONEROO_PUBLIC_KEY');
        $this->secretKey = env('MONEROO_SECRET_KEY');
    }

    /**
     * Initialize a payment link
     * 
     * @param float $amount
     * @param string $currency
     * @param string $ref
     * @param string $description
     * @param array $customer
     * @return array|null Returns ['payment_link' => 'url', 'id' => 'payment_id']
     */
    public function initializePayment(float $amount, string $currency, string $ref, string $description, array $customer)
    {
        if (!$this->publicKey || !$this->secretKey) {
            // Log error or throw
            throw new Exception("Moneroo keys not configured.");
        }

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customer, // ['email' => '...', 'first_name' => '...', 'last_name' => '...']
            'return_url' => env('APP_URL') . "/payment/success", // Placeholder return URL
            'methods' => ['mobile_money', 'card'],
            'ref' => $ref,
            'description' => $description,
            // If Moneroo supported generating a direct QR Code image, we'd ask for it, 
            // but standard flow is getting a checkout URL.
        ];

        // Authorization: Bearer <Current-Secret-Key>
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/payments", $payload);

        if ($response->successful()) {
            $data = $response->json();
            // Data structure depends on Moneroo API usually data.url
            return [
                'checkout_url' => $data['data']['checkout_url'] ?? $data['data']['url'] ?? null,
                'id' => $data['data']['id'] ?? null,
            ];
        }

        throw new Exception("Moneroo Error: " . $response->body());
    }

    /**
     * Verify a transaction (backend verification)
     */
    public function verifyPayment($paymentId)
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/payments/{$paymentId}");

        return $response->json(); // Check status inside
    }
}
