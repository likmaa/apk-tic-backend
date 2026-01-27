<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\PaymentConfirmed;

class WalletController extends Controller
{
    protected function getOrCreateWallet(int $userId): array
    {
        $wallet = DB::table('wallets')->where('user_id', $userId)->first();
        if (!$wallet) {
            DB::table('wallets')->insert([
                'user_id' => $userId,
                'balance' => 0,
                'currency' => 'XOF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wallet = DB::table('wallets')->where('user_id', $userId)->first();
        }
        return (array) $wallet;
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $wallet = $this->getOrCreateWallet($user->id);

        return response()->json([
            'balance' => (int) $wallet['balance'],
            'currency' => $wallet['currency'],
        ]);
    }

    public function todayTransactions(Request $request)
    {
        $user = $request->user();
        $wallet = $this->getOrCreateWallet($user->id);

        $today = now()->toDateString();

        $rows = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet['id'])
            ->whereDate('created_at', $today)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $transactions = $rows->map(function ($row) {
            $time = $row->created_at ? date('H:i', strtotime((string) $row->created_at)) : null;

            // Mapping simple pour l’app mobile
            $label = match ($row->source) {
                'ride_payment' => 'Paiement course',
                'ride_earnings' => 'Gain course',
                'topup_cash' => 'Rechargement (espèces)',
                'topup_qr' => 'Rechargement (QR)',
                default => ucfirst(str_replace('_', ' ', (string) $row->source)),
            };

            return [
                'id' => $row->id,
                'type' => $row->type,       // credit | debit
                'source' => $row->source,
                'label' => $label,
                'amount' => (int) $row->amount,
                'time' => $time,
            ];
        });

        return response()->json([
            'wallet_id' => $wallet['id'],
            'date' => $today,
            'transactions' => $transactions,
        ]);
    }

    public function topup(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,qr'],
        ]);

        $wallet = null;

        DB::transaction(function () use ($user, $data, &$wallet) {
            $wallet = $this->getOrCreateWallet($user->id);
            $before = (int) $wallet['balance'];
            $after = $before + (int) $data['amount'];

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'type' => 'credit',
                'source' => $data['method'] === 'cash' ? 'topup_cash' : 'topup_qr',
                'amount' => (int) $data['amount'],
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => null,
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet['id'])->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            $wallet['balance'] = $after;
        });

        return response()->json([
            'ok' => true,
            'balance' => (int) $wallet['balance'],
            'currency' => 'XOF',
        ]);
    }

    public function payRide(Request $request, int $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'method' => ['required', 'in:cash,wallet,qr'],
        ]);

        $result = null;

        DB::transaction(function () use ($user, $data, $id, &$result) {
            $ride = Ride::where('id', $id)->where('rider_id', $user->id)->firstOrFail();
            if ($ride->status !== 'completed') {
                abort(response()->json(['message' => 'Ride not completed'], 422));
            }

            $existing = DB::table('payments')
                ->where('ride_id', $ride->id)
                ->where('status', 'succeeded')
                ->first();
            if ($existing) {
                abort(response()->json(['message' => 'Ride already paid'], 409));
            }

            $amount = (int) $ride->fare_amount;
            $currency = $ride->currency ?? 'XOF';
            $status = 'succeeded';

            if ($data['method'] === 'wallet') {
                $wallet = $this->getOrCreateWallet($user->id);
                $before = (int) $wallet['balance'];
                if ($before < $amount) {
                    abort(response()->json(['message' => 'insufficient_funds'], 422));
                }
                $after = $before - $amount;

                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $wallet['id'],
                    'type' => 'debit',
                    'source' => 'ride_payment',
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'meta' => json_encode(['ride_id' => $ride->id]),
                    'created_at' => now(),
                ]);

                DB::table('wallets')->where('id', $wallet['id'])->update([
                    'balance' => $after,
                    'updated_at' => now(),
                ]);

                // Credit Driver Wallet
                if ($ride->driver_id) {
                    $earnings = $ride->driver_earnings_amount;
                    if ($earnings > 0) {
                        $driverWallet = DB::table('wallets')->where('user_id', $ride->driver_id)->first();
                        if (!$driverWallet) {
                            $dWid = DB::table('wallets')->insertGetId([
                                'user_id' => $ride->driver_id,
                                'balance' => 0,
                                'currency' => $ride->currency ?? 'XOF',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $driverWallet = (object) ['id' => $dWid, 'balance' => 0];
                        }

                        $dBefore = (int) $driverWallet->balance;
                        $dAfter = $dBefore + $earnings;

                        DB::table('wallet_transactions')->insert([
                            'wallet_id' => $driverWallet->id,
                            'type' => 'credit',
                            'source' => 'ride_earnings',
                            'amount' => $earnings,
                            'balance_before' => $dBefore,
                            'balance_after' => $dAfter,
                            'meta' => json_encode(['ride_id' => $ride->id]),
                            'created_at' => now(),
                        ]);

                        DB::table('wallets')->where('id', $driverWallet->id)->update([
                            'balance' => $dAfter,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $paymentId = DB::table('payments')->insertGetId([
                'ride_id' => $ride->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => $data['method'],
                'status' => $status,
                'meta' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update ride payment status
            $ride->payment_status = 'completed';
            $ride->save();

            // Notify everyone
            broadcast(new PaymentConfirmed($ride));

            $result = [
                'ok' => true,
                'payment_id' => $paymentId,
                'ride_id' => $ride->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => $data['method'],
                'status' => $status,
            ];
        });

        return response()->json($result);
    }

    public function adminReset(Request $request, int $userId)
    {
        // Réservé à un usage admin (route protégée côté routes/api.php)

        $result = null;

        DB::transaction(function () use ($userId, &$result) {
            $wallet = $this->getOrCreateWallet($userId);
            $before = (int) $wallet['balance'];

            if ($before === 0) {
                $result = [
                    'ok' => true,
                    'balance_before' => 0,
                    'balance_after' => 0,
                    'currency' => $wallet['currency'],
                    'message' => 'Wallet déjà à 0.',
                ];
                return;
            }

            $after = 0;
            $amount = abs($before); // montant de l’ajustement

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet['id'],
                'type' => $before > 0 ? 'debit' : 'credit',
                'source' => 'admin_reset',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => null,
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet['id'])->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            $result = [
                'ok' => true,
                'balance_before' => $before,
                'balance_after' => $after,
                'currency' => $wallet['currency'],
            ];
        });

        return response()->json($result);
    }
}
