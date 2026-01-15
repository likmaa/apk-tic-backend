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
        if ($f === false)
            return false;

        if (!$adaptive)
            $buffer = 4096;
        else
            $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));

        fseek($f, -1, SEEK_END);
        if (fread($f, 1) != "\n")
            $lines -= 1;

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

    /**
     * Reset all application data for production deployment.
     * POST /api/admin/dev/reset-data
     * 
     * ⚠️ DANGER: This will delete all rides, transactions, and reset wallets!
     */
    public function resetData(Request $request)
    {
        $data = $request->validate([
            'confirm' => ['required', 'string', 'in:RESET_ALL_DATA'],
        ]);

        if ($data['confirm'] !== 'RESET_ALL_DATA') {
            return response()->json(['message' => 'Confirmation invalide'], 422);
        }

        // Tables to clear
        $tables = [
            'wallet_transactions',
            'rides',
            'notifications',
            'otp_requests',
            'ratings',
            'geocoding_logs',
            'payments',
            'analytics_reconnections',
            'app_metrics',
        ];

        \DB::beginTransaction();
        try {
            // Count before deletion to report
            $counts = [];
            foreach ($tables as $table) {
                if (\Schema::hasTable($table)) {
                    $counts[$table] = \DB::table($table)->count();
                }
            }

            // Disable foreign key checks
            \DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tables as $table) {
                if (\Schema::hasTable($table)) {
                    // Using DELETE instead of TRUNCATE for better compatibility on shared hosting
                    \DB::table($table)->delete();
                    // Optional: Reset auto-increment
                    \DB::statement("ALTER TABLE $table AUTO_INCREMENT = 1");
                }
            }

            // Re-enable foreign key checks
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Reset all wallet balances to 0
            if (\Schema::hasTable('wallets')) {
                \DB::table('wallets')->update(['balance' => 0]);
            }

            // Clear the log file if reachable
            try {
                $logPath = storage_path('logs/laravel.log');
                if (file_exists($logPath) && is_writable($logPath)) {
                    file_put_contents($logPath, '');
                }
            } catch (\Exception $e) {
                // Ignore log clear errors
            }

            \DB::commit();

            try {
                \Log::info('Developer reset data executed', [
                    'admin_id' => auth()->id(),
                    'deleted' => $counts,
                ]);
            } catch (\Exception $e) {
                // Ignore logging errors if storage is not writable
            }

            return response()->json([
                'ok' => true,
                'message' => 'Toutes les données ont été réinitialisées avec succès.',
                'deleted' => $counts,
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();

            // Try to log the error but don't crash if log fails
            try {
                \Log::error('Reset data failed', ['error' => $e->getMessage()]);
            } catch (\Exception $logEx) {
            }

            return response()->json([
                'message' => 'Erreur lors de la réinitialisation (BD)',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}


