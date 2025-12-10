<?php

/**
 * Script de test pour diagnostiquer les problèmes KYA SMS
 * 
 * Usage: php test-kyasms.php +229XXXXXXXX
 */

require __DIR__ . '/vendor/autoload.php';

$phone = $argv[1] ?? null;

if (!$phone) {
    echo "Usage: php test-kyasms.php +229XXXXXXXX\n";
    exit(1);
}

$apiKey = 'kyasms661efc85b7b3c8f0d90cd7f21097e731e05b029cedcf265319b853dd67';
$baseUrl = 'https://route.kyasms.com/api/v3';
$appId = '9DILGC5Y';

echo "=== Test KYA SMS OTP ===\n\n";
echo "Numéro: $phone\n";
echo "API Key: " . substr($apiKey, 0, 20) . "...\n";
echo "Base URL: $baseUrl\n";
echo "App ID: $appId\n\n";

// Normaliser le numéro (enlever le +)
$recipient = ltrim($phone, '+');
echo "Recipient (sans +): $recipient\n\n";

// Payload pour /otp/create
$payload = [
    'appId'    => $appId,
    'recipient'=> $recipient,
    'lang'     => 'fr',
];

echo "=== 1. Test de connexion à KYA SMS ===\n";
echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $baseUrl . '/otp/create',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'APIKEY: ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 30,
]);

echo "Envoi de la requête...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "\n=== Résultat ===\n";
echo "Code HTTP: $httpCode\n";

if ($error) {
    echo "Erreur cURL: $error\n";
    exit(1);
}

echo "Réponse brute:\n$response\n\n";

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Erreur de parsing JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "Réponse parsée:\n";
print_r($data);

if ($httpCode === 200 && isset($data['key'])) {
    echo "\n✅ SUCCÈS: Code OTP envoyé avec succès!\n";
    echo "Clé OTP: " . $data['key'] . "\n";
    echo "Raison: " . ($data['reason'] ?? 'N/A') . "\n";
} else {
    echo "\n❌ ÉCHEC: L'envoi a échoué\n";
    if (isset($data['message'])) {
        echo "Message: " . $data['message'] . "\n";
    }
    if (isset($data['error'])) {
        echo "Erreur: " . $data['error'] . "\n";
    }
}

