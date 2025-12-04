<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ride;
use App\Models\DriverReward;

class FinanceController extends Controller
{
    public function summary(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $now = now();

        $rangeFrom = $from ? $from : $now->copy()->startOfMonth()->toISOString();
        $rangeTo = $to ? $to : $now->toISOString();

        $ridesQuery = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$rangeFrom, $rangeTo]);

        $grossVolume = (int) $ridesQuery->sum('fare_amount');
        $netRevenue = (int) $ridesQuery->sum('commission_amount');
        $ridesCount = (int) $ridesQuery->count();

        $commissionRate = $grossVolume > 0 ? ($netRevenue / $grossVolume) : 0.0;

        $rewardsCount = (int) DriverReward::query()
            ->whereBetween('created_at', [$rangeFrom, $rangeTo])
            ->count();

        return response()->json([
            'range' => [
                'from' => $rangeFrom,
                'to' => $rangeTo,
            ],
            'gross_volume' => $grossVolume,
            'net_revenue' => $netRevenue,
            'commission_rate' => $commissionRate,
            'rides_count' => $ridesCount,
            'payouts_pending' => $rewardsCount,
        ]);
    }

    public function transactions(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $rides = Ride::query()
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->get()
            ->map(function (Ride $ride) {
                return [
                    'id' => $ride->id,
                    'type' => 'ride_payment',
                    'amount' => (int) $ride->fare_amount,
                    'currency' => $ride->currency,
                    'status' => 'succeeded',
                    'created_at' => $ride->completed_at,
                ];
            })->all();

        $rewards = DriverReward::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (DriverReward $reward) {
                return [
                    'id' => $reward->id,
                    'type' => 'driver_reward',
                    'amount' => (int) $reward->amount,
                    'currency' => 'XOF',
                    'status' => 'succeeded',
                    'created_at' => $reward->created_at,
                ];
            })->all();

        $all = array_merge($rides, $rewards);

        usort($all, function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        $total = count($all);
        $offset = max(0, ($page - 1) * $perPage);
        $items = array_slice($all, $offset, $perPage);

        return response()->json([
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
    }
}
