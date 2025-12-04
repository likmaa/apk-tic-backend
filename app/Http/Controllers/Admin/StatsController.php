<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Ride;
use App\Models\User;

class StatsController extends Controller
{
    public function overview(Request $request)
    {
        $now = Carbon::now();
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay = $now->copy()->endOfDay();

        $onlineDrivers = User::query()
            ->where('role', 'driver')
            ->where('is_active', true)
            ->where('is_online', true)
            ->count();

        $activeRides = Ride::query()
            ->whereIn('status', ['requested', 'accepted', 'ongoing'])
            ->count();

        $todayCompletedQuery = Ride::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startOfDay, $endOfDay]);

        $todayCompletedCount = (int) $todayCompletedQuery->count();
        $todayRevenueAmount = (int) $todayCompletedQuery->sum('fare_amount');

        return response()->json([
            'online_drivers' => $onlineDrivers,
            'active_rides' => $activeRides,
            'today_completed_rides' => $todayCompletedCount,
            'today_revenue' => [
                'amount' => $todayRevenueAmount,
                'currency' => 'XOF',
            ],
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    public function driversDaily(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'driver_id' => ['required','integer','exists:users,id'],
            'tz' => ['nullable','string','max:64'],
        ]);

        $tz = $data['tz'] ?? 'UTC';
        $now = Carbon::now($tz);
        $fromLocal = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        $toLocal = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : $now->copy()->endOfDay();
        $from = $fromLocal->copy()->setTimezone('UTC');
        $to = $toLocal->copy()->setTimezone('UTC');

        if ($from->gt($to)) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $driverId = (int) $data['driver_id'];

        $cur = $from->copy()->startOfDay();
        $dateMap = [];
        while ($cur->lte($to)) {
            $dateMap[$cur->toDateString()] = [
                'date' => $cur->toDateString(),
                'total_rides' => 0,
                'completed_rides' => 0,
                'cancelled_rides' => 0,
                'gross_volume' => 0,
                'commission_total' => 0,
                'earnings_total' => 0,
                'currency' => 'XOF',
            ];
            $cur->addDay();
        }

        $completed = Ride::query()
            ->selectRaw('DATE(completed_at) as d, COUNT(*) as c, COALESCE(SUM(fare_amount),0) as gross, COALESCE(SUM(commission_amount),0) as comm, COALESCE(SUM(driver_earnings_amount),0) as earn')
            ->where('driver_id', $driverId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($completed as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['completed_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
            $dateMap[$d]['gross_volume'] = (int) $row->gross;
            $dateMap[$d]['commission_total'] = (int) $row->comm;
            $dateMap[$d]['earnings_total'] = (int) $row->earn;
        }

        $cancelled = Ride::query()
            ->selectRaw('DATE(cancelled_at) as d, COUNT(*) as c')
            ->where('driver_id', $driverId)
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($cancelled as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['cancelled_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
        }

        $out = array_values($dateMap);

        return response()->json([
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'timezone' => $tz,
            'driver_id' => $driverId,
            'data' => $out,
        ]);
    }

    public function driversDailyGlobal(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'tz' => ['nullable','string','max:64'],
        ]);

        $tz = $data['tz'] ?? 'UTC';
        $now = Carbon::now($tz);
        $fromLocal = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        $toLocal = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : $now->copy()->endOfDay();
        $from = $fromLocal->copy()->setTimezone('UTC');
        $to = $toLocal->copy()->setTimezone('UTC');

        if ($from->gt($to)) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $cur = $from->copy()->startOfDay();
        $dateMap = [];
        while ($cur->lte($to)) {
            $dateMap[$cur->toDateString()] = [
                'date' => $cur->toDateString(),
                'total_rides' => 0,
                'completed_rides' => 0,
                'cancelled_rides' => 0,
                'gross_volume' => 0,
                'commission_total' => 0,
                'earnings_total' => 0,
                'currency' => 'XOF',
            ];
            $cur->addDay();
        }

        $completed = Ride::query()
            ->selectRaw('DATE(completed_at) as d, COUNT(*) as c, COALESCE(SUM(fare_amount),0) as gross, COALESCE(SUM(commission_amount),0) as comm, COALESCE(SUM(driver_earnings_amount),0) as earn')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($completed as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['completed_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
            $dateMap[$d]['gross_volume'] = (int) $row->gross;
            $dateMap[$d]['commission_total'] = (int) $row->comm;
            $dateMap[$d]['earnings_total'] = (int) $row->earn;
        }

        $cancelled = Ride::query()
            ->selectRaw('DATE(cancelled_at) as d, COUNT(*) as c')
            ->where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d')
            ->get();

        foreach ($cancelled as $row) {
            $d = (string) $row->d;
            if (!isset($dateMap[$d])) continue;
            $dateMap[$d]['cancelled_rides'] = (int) $row->c;
            $dateMap[$d]['total_rides'] += (int) $row->c;
        }

        $out = array_values($dateMap);

        return response()->json([
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'timezone' => $tz,
            'data' => $out,
        ]);
    }

    public function topDriversDaily(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'tz' => ['nullable','string','max:64'],
            'limit' => ['nullable','integer','min:1','max:50'],
        ]);

        $limit = (int) ($data['limit'] ?? 10);
        $tz = $data['tz'] ?? 'UTC';
        $now = Carbon::now($tz);
        $fromLocal = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
        $toLocal = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : $now->copy()->endOfDay();
        $from = $fromLocal->copy()->setTimezone('UTC');
        $to = $toLocal->copy()->setTimezone('UTC');

        if ($from->gt($to)) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $rows = Ride::query()
            ->leftJoin('users', 'users.id', '=', 'rides.driver_id')
            ->selectRaw('DATE(rides.completed_at) as d, rides.driver_id, users.name as driver_name, users.phone as driver_phone, COUNT(*) as completed, COALESCE(SUM(rides.fare_amount),0) as gross, COALESCE(SUM(rides.commission_amount),0) as comm, COALESCE(SUM(rides.driver_earnings_amount),0) as earn')
            ->where('rides.status', 'completed')
            ->whereBetween('rides.completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('d', 'rides.driver_id', 'users.name', 'users.phone')
            ->get();

        $byDate = [];
        foreach ($rows as $r) {
            $d = (string) $r->d;
            if (!array_key_exists($d, $byDate)) {
                $byDate[$d] = [];
            }
            $byDate[$d][] = [
                'driver_id' => (int) $r->driver_id,
                'driver_name' => $r->driver_name,
                'driver_phone' => $r->driver_phone,
                'completed_rides' => (int) $r->completed,
                'gross_volume' => (int) $r->gross,
                'commission_total' => (int) $r->comm,
                'earnings_total' => (int) $r->earn,
                'currency' => 'XOF',
            ];
        }

        $cur = $from->copy()->startOfDay();
        $result = [];
        while ($cur->lte($to)) {
            $key = $cur->toDateString();
            $list = $byDate[$key] ?? [];
            usort($list, function ($a, $b) {
                if ($a['earnings_total'] === $b['earnings_total']) {
                    if ($a['completed_rides'] === $b['completed_rides']) {
                        return $a['driver_id'] <=> $b['driver_id'];
                    }
                    return $b['completed_rides'] <=> $a['completed_rides'];
                }
                return $b['earnings_total'] <=> $a['earnings_total'];
            });
            $result[] = [
                'date' => $key,
                'top' => array_slice($list, 0, $limit),
            ];
            $cur->addDay();
        }

        return response()->json([
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'timezone' => $tz,
            'limit' => $limit,
            'data' => $result,
        ]);
    }
}
