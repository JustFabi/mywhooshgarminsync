<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Device;
use App\Support\ConnectionState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $base = Activity::query()->where('user_id', $user->id);

        $stats = [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', Activity::STATUS_PENDING)->count(),
            'synced' => (clone $base)->where('status', Activity::STATUS_SYNCED)->count(),
            'failed' => (clone $base)->where('status', Activity::STATUS_FAILED)->count(),
        ];

        $watch = $user->selectedDevice ?? Device::default();

        $recent = (clone $base)
            ->orderByDesc('start_at')
            ->limit(5)
            ->with('device')
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'title' => $a->title ?? 'Activity',
                'start_at' => optional($a->start_at)->toIso8601String(),
                'status' => $a->status,
                'distance_km' => $a->distance_km,
                'device' => $a->device?->name,
            ]);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'mywhoosh' => ConnectionState::myWhoosh($user),
            'garmin' => ConnectionState::garmin($user),
            'watch' => $watch !== null ? [
                'id' => $watch->id,
                'name' => $watch->name,
                'product_id' => (int) $watch->product_id,
            ] : null,
            'recent' => $recent,
        ]);
    }
}
