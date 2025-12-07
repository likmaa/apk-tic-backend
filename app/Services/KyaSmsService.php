<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KyaSmsService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected ?string $from;

    public function __construct()
    {
        // TODO: remettre la clé et le sender en lecture depuis l'env une fois les tests terminés.
        $this->apiKey = 'kyasms661efc85b7b3c8f0d90cd7f21097e731e05b029cedcf265319b853dd67';
        // Serveur principal
        $this->baseUrl =  'https://route.kyasms.com/api/v3';
        // Sender ID utilisé pour KYA SMS (hardcodé pour debug)
        $this->from = 'TICMITON';;
    }

    public function sendSmsMessage(string $to, string $message): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('KYASMS_API_KEY manquant.');
        }

        if ($this->from === null || $this->from === '') {
            throw new \RuntimeException('KYASMS_FROM manquant. Définissez un Sender ID fourni par KYA SMS.');
        }

        $payload = [
            'from' => $this->from,
            'to' => $to,
            'type' => 'text',
            'message' => $message,
            'isBulk' => false,
        ];

        $response = Http::withHeaders([
            'APIKEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/sms/send', $payload);

        if (!$response->ok()) {
            $body = $response->body();
            throw new \RuntimeException('KYA SMS send failed with status ' . $response->status() . ' body: ' . $body);
        }

        return $response->json();
    }

    public function sendOtp(string $phone, bool $forceNew = false): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('KYASMS_API_KEY manquant.');
        }

        // Si une clé OTP existe déjà pour ce numéro et qu'on ne force pas :
        // on renvoie une réponse indiquant qu'un code est déjà en cours,
        // afin que le client passe directement à la vérification.
        // Si $forceNew est true, on supprime l'ancien cache et on envoie un nouveau code.
        $cacheKey = 'kya_otp_key_' . $phone;
        $existingKey = cache()->get($cacheKey);
        
        if ($existingKey && !$forceNew) {
            Log::info('KYA SMS OTP already exists for phone, skipping create', [
                'phone' => $phone,
                'key'   => $existingKey,
            ]);

            return [
                'reason' => 'already_exists',
                'key'    => $existingKey,
            ];
        }
        
        // Si on force un nouveau code, supprimer l'ancien cache
        if ($forceNew && $existingKey) {
            Log::info('KYA SMS OTP force new code, clearing old cache', [
                'phone' => $phone,
                'old_key' => $existingKey,
            ]);
            cache()->forget($cacheKey);
        }

        // Payload conforme à la doc KYA OTP: /otp/create
        $payload = [
            'appId'    => '9DILGC5Y',      // ton app OTP KYA
            'recipient'=> ltrim($phone, '+'), // ex: 22966223344 (selon doc)
            'lang'     => 'fr',
        ];

        Log::info('KYA SMS OTP send payload', $payload);

        $response = Http::withHeaders([
            'APIKEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/otp/create', $payload);

        Log::info('KYA SMS OTP send response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->ok()) {
            $body = $response->body();
            throw new \RuntimeException('KYA SMS OTP send failed with status ' . $response->status() . ' body: ' . $body);
        }

        $data = $response->json();

        // On s'attend à quelque chose comme: { "reason": "success", "key": "..." }
        if (!isset($data['key'])) {
            throw new \RuntimeException('KYA SMS OTP send response missing key field: ' . $response->body());
        }

        // On stocke la key associée au numéro pour la vérification ultérieure
        // Expiration après 10 minutes (temps de validité du code OTP)
        cache()->put($cacheKey, $data['key'], now()->addMinutes(10));

        return $data;
    }

    public function verifyOtp(string $otpKey, string $code): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('KYASMS_API_KEY manquant.');
        }

        // Payload conforme à la doc KYA OTP: /otp/verify
        $payload = [
            'appId' => '9DILGC5Y',
            'key'   => $otpKey,
            'code'  => $code,
        ];

        Log::info('KYA SMS OTP verify payload', $payload);

        $response = Http::withHeaders([
            'APIKEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/otp/verify', $payload);

        Log::info('KYA SMS OTP verify response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->ok()) {
            $body = $response->body();
            throw new \RuntimeException('KYA SMS OTP verify failed with status ' . $response->status() . ' body: ' . $body);
        }

        $data = $response->json();

        return $data;
    }
}

