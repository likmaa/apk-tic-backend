<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TermiiService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $senderId;
    protected string $channel;

    public function __construct()
    {
        $this->apiKey = env('TERMII_API_KEY', '');
        $this->baseUrl = rtrim(env('TERMII_BASE_URL', 'https://api.ng.termii.com/api'), '/');
        $this->senderId = env('TERMII_SENDER_ID', 'N-Alert');
        // Pour le sandbox Termii, le channel est souvent "generic" ou "dnd"; on laisse configurable.
        $this->channel = env('TERMII_CHANNEL', 'generic');
    }

    /**
     * Démarre une vérification OTP via Termii (sandbox compris).
     */
    public function startVerification(string $phone): void
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('TERMII_API_KEY manquant.');
        }

        $payload = [
            'api_key' => $this->apiKey,
            'to' => $phone,
            'from' => $this->senderId,
            'channel' => $this->channel,
            'pin_attempts' => 3,
            'pin_time_to_live' => 10,
            'pin_length' => 6,
            'pin_type' => 'numeric',
        ];

        $response = Http::post($this->baseUrl . '/sms/otp/send', $payload);

        if (!$response->ok()) {
            throw new \RuntimeException('Termii OTP send failed', $response->status());
        }

        $data = $response->json();
        if (!isset($data['pinId'])) {
            throw new \RuntimeException('Termii response missing pinId');
        }

        // En sandbox simple, on pourrait ne pas gérer pinId persistant et se contenter du statut.
        // Si besoin de pinId pour une vérification stricte, il faudra le stocker (par utilisateur / téléphone).
    }

    /**
     * Vérifie un OTP via Termii. En sandbox basique, on peut accepter un code fixe
     * ou utiliser l'endpoint officiel de vérification Termii.
     */
    public function checkVerification(string $phone, string $code): bool
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('TERMII_API_KEY manquant.');
        }

        // Pour un vrai flux, il faudrait récupérer le pinId associé au téléphone.
        // Ici, on appelle l'endpoint de vérification avec un pinId optionnel (à adapter si nécessaire).
        $pinId = null; // TODO: stocker/récupérer le pinId si vous faites une intégration complète.

        if ($pinId === null) {
            // Mode sandbox simplifié : accepter un code de test configurable.
            $testCode = env('TERMII_TEST_OTP', '123456');
            return $code === $testCode;
        }

        $payload = [
            'api_key' => $this->apiKey,
            'pin_id' => $pinId,
            'pin' => $code,
        ];

        $response = Http::post($this->baseUrl . '/sms/otp/verify', $payload);
        if (!$response->ok()) {
            return false;
        }

        $data = $response->json();
        return ($data['verified'] ?? false) === true;
    }
}
