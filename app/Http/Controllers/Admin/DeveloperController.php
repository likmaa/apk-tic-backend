<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class DeveloperController extends Controller
{
    public function logs(Request $request)
    {
        $path = storage_path('logs/laravel.log');
        
        if (!File::exists($path)) {
            return response()->json(['content' => 'Fichier de log non trouvé.']);
        }

        // Read last 200 lines to avoid memory issues
        $content = $this->tailCustom($path, 200);

        return response()->json(['content' => $content]);
    }

    /**
     * Efficiently read the end of a file
     */
    private function tailCustom($filepath, $lines = 100, $adaptive = true)
    {
        $f = @fopen($filepath, "rb");
        if ($f === false) return false;

        if (!$adaptive) $buffer = 4096;
        else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));

        fseek($f, -1, SEEK_END);
        if (fread($f, 1) != "\n") $lines -= 1;
        
        $output = '';
        $chunk = '';

        while (ftell($f) > 0 && $lines >= 0) {
            $seek = min(ftell($f), $buffer);
            fseek($f, -$seek, SEEK_CUR);
            $chunk = fread($f, $seek);
            $output = $chunk . $output;
            fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);

            $lines -= substr_count($chunk, "\n");
        }

        // If we read too many lines, trim the beginning
        // (This is a simplified tail, sufficient for logs)
        $split = explode("\n", $output);
        if (count($split) > $lines) {
            $split = array_slice($split, -$lines);
        }

        fclose($f);
        return implode("\n", $split);
    }
}
