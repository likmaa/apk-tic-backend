<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    /**
     * Sert un fichier depuis le disque public de stockage.
     * Route : GET /api/storage/{path}
     * Exemple : /api/storage/profiles/abc123.jpg
     */
    public function show(string $path)
    {
        // Sécurité : empêcher la traversée de répertoires
        $path = str_replace(['..', '//'], '', $path);

        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'Fichier introuvable.');
        }

        $fullPath = Storage::disk('public')->path($path);
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
