<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Device;
use App\Services\Sync\ActivitySyncService;
use App\Support\LocalUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityController extends Controller
{
    public function index(): Response
    {
        $user = LocalUser::get();

        // Ensure the device catalog exists before first use.
        \App\Support\GarminDevices::seed();

        $watch = $user->selectedDevice ?? Device::default();

        $activities = Activity::query()
            ->where('user_id', $user->id)
            ->orderByDesc('start_at')
            ->with('device')
            ->simplePaginate(25, ['*'], 'page')
            ->withQueryString();

        return Inertia::render('Activities', [
            'activities' => [
                'data' => collect($activities->items())->map(fn (Activity $a) => $this->serialize($a)),
                'has_more' => $activities->hasMorePages(),
                'next_page' => $activities->hasMorePages() ? $activities->currentPage() + 1 : null,
                'prev_page' => $activities->currentPage() > 1 ? $activities->currentPage() - 1 : null,
            ],
            'watch' => $watch !== null ? [
                'id' => $watch->id,
                'name' => $watch->name,
                'product_id' => (int) $watch->product_id,
            ] : null,
        ]);
    }

    /**
     * Import activities from the connected MyWhoosh account.
     */
    public function fetch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        $user = LocalUser::get();
        $service = new \App\Services\MyWhoosh\MyWhooshService($user);

        $imported = 0;
        try {
            $activities = $service->call(fn ($client) => $client->activitiesWithinDays((int) $data['days']));

            foreach ($activities as $myWhooshActivity) {
                Activity::query()->updateOrCreate(
                    ['user_id' => $user->id, 'mywhoosh_id' => $myWhooshActivity->id],
                    $this->attributesFromMyWhoosh($myWhooshActivity),
                );
                $imported++;
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'MyWhoosh fetch failed: '.$e->getMessage());
        }

        if ($imported === 0) {
            return back()->with('success', 'No rides found in the last '.$data['days'].' day(s)');
        }

        return back()->with(
            'success',
            sprintf('Imported %d ride%s from the last %d day(s)', $imported, $imported === 1 ? '' : 's', $data['days']),
        );
    }

    /**
     * Sync one activity to Garmin. Called by the frontend loop, activity by
     * activity, so a long list can never exceed PHP's request timeout.
     */
    public function sync(Activity $activity): JsonResponse
    {
        $device = $this->deviceFor($activity);

        $result = (new ActivitySyncService)->sync($activity, $device);

        return response()->json([
            'id' => $activity->id,
            'status' => $result['status'],
            'message' => $result['message'],
            'log' => $result['log'],
            'error' => $activity->error_message,
            'synced_at' => $activity->refresh()->synced_at?->toIso8601String(),
            'device' => $device->name,
        ]);
    }

    /**
     * Re-fix an activity's FIT file with the chosen watch (no upload).
     */
    public function fix(Activity $activity): JsonResponse
    {
        $device = $this->deviceFor($activity);
        $log = (new ActivitySyncService)->fix($activity, $device);

        return response()->json([
            'id' => $activity->id,
            'log' => $log,
            'device' => $device->name,
            'has_file' => (bool) $activity->refresh()->fit_fixed_path,
        ]);
    }

    public function download(Activity $activity)
    {
        // Fix on demand when no file exists yet.
        if (! $activity->fit_fixed_path || ! Storage::exists($activity->fit_fixed_path)) {
            $device = $this->deviceFor($activity);
            (new ActivitySyncService)->fix($activity, $device);

            abort_unless($activity->refresh()->fit_fixed_path && Storage::exists($activity->fit_fixed_path), 422, 'FIT file could not be produced');
        }

        $title = str($activity->title ?? 'activity')->slug();
        $watch = $activity->device?->name ? str($activity->device->name)->slug() : 'fixed';

        return Storage::download($activity->fit_fixed_path, $title.'-'.$watch.'.fit');
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        if ($activity->fit_fixed_path) {
            Storage::delete($activity->fit_fixed_path);
        }

        $activity->delete();

        return back()->with('success', 'Activity removed from the list (it stays on MyWhoosh)');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function deviceFor(Activity $activity): Device
    {
        return Device::query()->find($activity->device_id)
            ?? LocalUser::get()->selectedDevice
            ?? Device::default()
            ?? throw new \RuntimeException('No watch available — nothing in the device catalog');
    }

    private function attributesFromMyWhoosh($a): array
    {
        return [
            'title' => $a->title,
            'start_at' => $a->startAt(),
            'activity_file_id' => $a->activityFileId,
            'distance_km' => $a->distance,
            'duration_seconds' => $a->durationSeconds(),
            'watt' => $a->watt,
            'heartrate' => $a->heartrate !== null ? (int) round($a->heartrate) : null,
            'elevation_m' => $a->elevation,
            'watt_per_kg' => $a->wattPerKg,
            'sport_type' => $a->sportType,
            'route_name' => $a->routeName,
        ];
    }

    /**
     * Shape for the frontend.
     */
    private function serialize(Activity $a): array
    {
        return [
            'id' => $a->id,
            'mywhoosh_id' => $a->mywhoosh_id,
            'title' => $a->title ?? 'Activity',
            'start_at' => optional($a->start_at)->toIso8601String(),
            'distance_km' => $a->distance_km,
            'duration' => $a->duration,       // formatted "1h31m54s"
            'watt' => $a->watt,
            'heartrate' => $a->heartrate,
            'watt_per_kg' => $a->watt_per_kg,
            'sport_type' => $a->sport_type,
            'route_name' => $a->route_name,
            'status' => $a->status,
            'error_message' => $a->error_message,
            'synced_at' => optional($a->synced_at)->toIso8601String(),
            'device' => $a->device?->name,
            'has_file' => (bool) $a->fit_fixed_path,
        ];
    }
}
