<?php

namespace App\Services\Sync;

use App\Models\Activity;
use App\Models\Device;
use App\Services\Fit\FitFixer;
use App\Services\Garmin\GarminClient;
use App\Services\Garmin\GarminDuplicateException;
use App\Services\Garmin\GarminService;
use App\Services\MyWhoosh\MyWhooshClient;
use App\Services\MyWhoosh\MyWhooshService;
use Illuminate\Support\Facades\Storage;

/**
 * Façade for one activity: download FIT from MyWhoosh → fix it
 * (session averages / temperature / spoofed watch) → upload to Garmin.
 *
 * The fixed FIT is kept on disk so it can be downloaded again at any time.
 */
final class ActivitySyncService
{
    // ------------------------------------------------------------------
    // Fix only (no upload)
    // ------------------------------------------------------------------

    /**
     * Download and fix the FIT for an activity with the given watch.
     *
     * @return array<int, string> fix log
     */
    public function fix(Activity $activity, Device $device): array
    {
        try {
            [, $log] = $this->fixedBytes($activity, $device, fresh: true);
            $activity->update(['device_id' => $device->id]);

            return $log;
        } catch (\Throwable $e) {
            return ['! error: '.$e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Full sync
    // ------------------------------------------------------------------

    /**
     * Download → fix → upload → persist outcome.
     *
     * @return array{status: string, message: string, log: array<int, string>}
     */
    public function sync(Activity $activity, Device $device): array
    {
        $deviceId = $device->id;

        try {
            [$bytes, $log] = $this->fixedBytes($activity, $device);
        } catch (\Throwable $e) {
            return $this->fail($activity, $deviceId, $e);
        }

        // Upload.
        try {
            $client = $this->garminClient($activity);
            $client->uploadFit($bytes, $this->filename($activity, $device));

            $this->markSynced($activity, $deviceId);

            $log[] = '→ uploaded to Garmin Connect';

            return [
                'status' => Activity::STATUS_SYNCED,
                'message' => 'Synced to Garmin Connect',
                'log' => $log,
            ];
        } catch (GarminDuplicateException) {
            $this->markSynced($activity, $deviceId);

            $log[] = '→ already on Garmin Connect (duplicate) — marked as synced';

            return [
                'status' => Activity::STATUS_SYNCED,
                'message' => 'Already on Garmin Connect — marked as synced',
                'log' => $log,
            ];
        } catch (\Throwable $e) {
            return $this->fail($activity, $deviceId, $e, $log);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Download (respecting a cached fix when present) and fix the FIT.
     *
     * @return array{0: string, 1: array<int, string>} [fixedBytes, log]
     */
    private function fixedBytes(Activity $activity, Device $device, bool $fresh = false): array
    {
        $path = $this->storagePath($activity);

        if (!$fresh && $activity->fit_fixed_path && Storage::exists($activity->fit_fixed_path)) {
            return [(string) Storage::get($activity->fit_fixed_path), []];
        }

        $mywhoosh = new MyWhooshService($activity->user);
        $bytes = $mywhoosh->call(
            fn (MyWhooshClient $client) => $client->downloadFit((string) $activity->activity_file_id),
        );

        $log = ['→ downloaded FIT from MyWhoosh ('.strlen($bytes).' bytes)'];

        [$fixed, $fixLines, $warnings] = FitFixer::fix($bytes, [
            'name' => $device->name,
            'product_id' => $device->product_id,
        ]);

        foreach ($warnings as $warning) {
            if (! in_array($warning, $fixLines, true)) {
                $fixLines[] = '! '.$warning;
            }
        }

        array_push($log, ...$fixLines);

        Storage::put($path, $fixed);
        $activity->update(['fit_fixed_path' => $path]);
        $log[] = '→ fixed FIT saved as "'.$path.'"';

        return [$fixed, $log];
    }

    private function garminClient(Activity $activity): GarminClient
    {
        return (new GarminService($activity->user))->client();
    }

    private function markSynced(Activity $activity, ?int $deviceId): void
    {
        $activity->update([
            'status' => Activity::STATUS_SYNCED,
            'synced_at' => now(),
            'error_message' => null,
            'device_id' => $deviceId ?? $activity->device_id,
        ]);
    }

    /**
     * @param  array<int, string>  $log
     * @return array{status: string, message: string, log: array<int, string>}
     */
    private function fail(Activity $activity, ?int $deviceId, \Throwable $e, array $log = []): array
    {
        $activity->update([
            'status' => Activity::STATUS_FAILED,
            'error_message' => $e->getMessage(),
            'device_id' => $deviceId ?? $activity->device_id,
        ]);

        $log[] = '! error: '.$e->getMessage();

        return [
            'status' => Activity::STATUS_FAILED,
            'message' => $e->getMessage(),
            'log' => $log,
        ];
    }

    /** Deterministic file name: watch id + MyWhoosh activity id. */
    private function filename(Activity $activity, Device $device): string
    {
        return 'w'.$device->product_id.'-'.$activity->mywhoosh_id.'.fit';
    }

    private function storagePath(Activity $activity): string
    {
        return 'fit/'.$activity->user_id.'/'.$activity->id.'.fit';
    }
}
