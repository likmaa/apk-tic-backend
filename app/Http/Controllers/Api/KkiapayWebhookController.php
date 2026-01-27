<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KkiapayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KkiapayWebhookController extends Controller
{
    protected KkiapayService $kkiapay;

    public function __construct(KkiapayService $kkiapay)
    {
        $this->kkiapay = $kkiapay;
    }

    /**
     * Handle KKiaPay Webhook notifications
     */
    public function handle(Request $request)
    {
        $transactionId = $request->input('transactionId');

        if (!$transactionId) {
            return response()->json(['message' => 'No transactionId provided'], 400);
        }

        Log::info('KKiaPay Webhook received', ['transactionId' => $transactionId]);

        // Verify the transaction with KKiaPay API for security
        $transaction = $this->kkiapay->verifyTransaction($transactionId);

        if (!$transaction || $transaction['status'] !== 'SUCCESS') {
            Log::warning('KKiaPay Webhook transaction verification failed', ['transaction' => $transaction]);
            return response()->json(['message' => 'Transaction verification failed'], 400);
        }

        // Process based on transaction type (from metadata)
        $externalId = $transaction['externalId'] ?? null;

        // Let's assume externalId contains user_id for topups
        if ($externalId && str_starts_with($externalId, 'topup_')) {
            $userId = (int) str_replace('topup_', '', $externalId);
            $amount = (int) $transaction['amount'];

            return $this->processTopup($userId, $amount, $transactionId);
        }

        return response()->json(['message' => 'Webhook processed but no action taken'], 200);
    }

    protected function processTopup(int $userId, int $amount, string $transactionId)
    {
        // Check if this transaction has already been processed
        $processed = DB::table('wallet_transactions')
            ->where('meta', 'like', "%$transactionId%")
            ->exists();

        if ($processed) {
            return response()->json(['message' => 'Transaction already processed'], 200);
        }

        DB::transaction(function () use ($userId, $amount, $transactionId) {
            $wallet = DB::table('wallets')->where('user_id', $userId)->first();

            if (!$wallet) {
                $walletId = DB::table('wallets')->insertGetId([
                    'user_id' => $userId,
                    'balance' => 0,
                    'currency' => 'XOF',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $walletBalance = 0;
            } else {
                $walletId = $wallet->id;
                $walletBalance = (int) $wallet->balance;
            }

            $after = $walletBalance + $amount;

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $walletId,
                'type' => 'credit',
                'source' => 'topup_kkiapay',
                'amount' => $amount,
                'balance_before' => $walletBalance,
                'balance_after' => $after,
                'meta' => json_encode(['transaction_id' => $transactionId, 'method' => 'kkiapay']),
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $walletId)->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);
        });

        Log::info('KKiaPay Topup successful', ['userId' => $userId, 'amount' => $amount]);

        return response()->json(['message' => 'Wallet topped up successfully'], 200);
    }
}
