<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class VoiceController extends Controller
{
    public function search(Request $request)
    {
        $request->validate([
            'audio' => ['required', 'file'],
        ]);

        $file = $request->file('audio');
        if (!$file || !$file->isValid()) {
            return response()->json(['error' => 'Fichier audio invalide'], 422);
        }

        $apiKey = env('GEMINI_API_KEY');
        if (!$apiKey) {
            return response()->json([
                'text' => 'centre commercial eros, cotonou',
                'warning' => 'GEMINI_API_KEY non configurée, utilisation d\'une transcription fictive',
            ]);
        }

        try {
            // Lire le fichier audio et l'encoder en base64 pour Gemini
            $binary = file_get_contents($file->getRealPath());
            $base64 = base64_encode($binary);

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => 'Transcris ce fichier audio en texte français.'],
                            [
                                'inline_data' => [
                                    'mime_type' => $file->getMimeType() ?? 'audio/m4a',
                                    'data' => $base64,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
                $payload
            );

            if (!$response->ok()) {
                return response()->json([
                    'error' => 'Échec de la requête de transcription Gemini',
                    'details' => $response->json(),
                ], 500);
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            return response()->json([
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erreur de transcription',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
