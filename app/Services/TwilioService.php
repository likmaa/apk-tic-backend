<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioService
{
    protected Client $client;
    protected string $verifySid;
    protected string $verifyChannel;
    protected ?string $whatsappFrom;

    public function __construct()
    {
        $sid = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');
        $this->verifySid = config('services.twilio.verify_sid');
        $this->verifyChannel = env('TWILIO_VERIFY_CHANNEL', 'whatsapp');
        $this->whatsappFrom = config('services.twilio.whatsapp_from');
        $this->client = new Client($sid, $token);
    }

    /**
     * Start a verification to a phone number in E.164 format using the configured channel.
     */
    public function startVerification(string $phone): void
    {
        $this->client->verify->v2->services($this->verifySid)
            ->verifications
            ->create($phone, $this->verifyChannel);
    }

    /**
     * Check a verification code sent to a phone number.
     */
    public function checkVerification(string $phone, string $code): bool
    {
        $check = $this->client->verify->v2->services($this->verifySid)
            ->verificationChecks
            ->create(['to' => $phone, 'code' => $code]);

        return ($check->status ?? null) === 'approved';
    }

    public function sendSmsMessage(string $to, string $body): string
    {
        $from = config('services.twilio.sms_from', env('TWILIO_PHONE_NUMBER', ''));
        if ($from === '') {
            throw new \RuntimeException('Numéro d\'envoi SMS Twilio manquant. Définissez TWILIO_PHONE_NUMBER ou services.twilio.sms_from.');
        }

        $message = $this->client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);

        return $message->sid;
    }

    public function sendWhatsAppMessage(string $to, string $body): string
    {
        $from = $this->whatsappFrom ?? '';
        if ($from === '') {
            throw new \RuntimeException('TWILIO_WHATSAPP_FROM manquant. Définissez-le dans backend/.env.');
        }
        $message = $this->client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);
        return $message->sid;
    }

    public function sendWhatsAppTemplate(string $to, string $contentSid, array $variables): string
    {
        $from = $this->whatsappFrom ?? '';
        if ($from === '') {
            throw new \RuntimeException('TWILIO_WHATSAPP_FROM manquant. Définissez-le dans backend/.env.');
        }
        $message = $this->client->messages->create($to, [
            'from' => $from,
            'contentSid' => $contentSid,
            'contentVariables' => json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return $message->sid;
    }
}

