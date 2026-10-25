<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Device;
use App\Services\MyWhoosh\MyWhooshService;
use App\Services\Sync\ActivitySyncService;
use App\Support\GarminDevices;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * CLI sync: import activities from MyWhoosh and upload pending ones to
 * Garmin with the selected spoof watch.
 *
 *   php artisan mywhoosh:sync --days=14
 *   php artisan mywhoosh:sync --days=30 --watch="fenix 7" --only=pending
 */
class MyWhooshSync extends Command
{
    protected $signature = 'mywhoosh:sync
        {--days=14 : Look this many days back when importing}
        {--watch= : Match a watch from the catalog by name fragment (default: your selected watch)}
        {--fetch-only : Import activities but don\'t upload}
        {--upload-only : Skip importing, upload pending activities only}';

    protected $description = 'Sync MyWhoosh activities to Garmin Connect (fixed FIT files, spoofed device)';

    public function handle(): int
    {
        GarminDevices::seed();

        $user = auth()->user() ?? \App\Models\User::query()->first();
        if ($user === null) {
            $this->error('No user exists — register in the webapp first.');

            return self::FAILURE;
        }

        // ---- watch resolution ----
        $device = $this->resolveDevice($user, $this->option('watch'));

        if ($device === null) {
            $this->error('No watch resolved — select one in the webapp (or use --watch="<name fragment>").');

            return self::FAILURE;
        }

        $this->info('Spoof watch: '.$device->name.' (product '.$device->product_id.')');

        // ---- import ----
        if (! $this->option('upload-only')) {
            $days = (int) $this->option('days');
            $imported = $this->import($user, $days);

            if ($imported === null) {
                return self::FAILURE;
            }

            if ($imported > 0) {
                $this->info("Imported/updated {$imported} activities from the last {$days} day(s).");
            }
        }

        if ($this->option('fetch-only')) {
            return self::SUCCESS;
        }

        // ---- upload ----
        $service = new ActivitySyncService;
        $pending = Activity::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Activity::STATUS_PENDING, Activity::STATUS_FAILED])
            ->orderBy('start_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->line('Nothing pending — everything is on Garmin.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($pending as $activity) {
            $this->line('');
            $this->line(str_repeat('-', 60));
            $this->line($activity->start_at->format('D d M Y H:i').'  '.$activity->title);

            $result = $service->sync($activity, $device);

            foreach ($result['log'] as $line) {
                Str::startsWith($line, '!')
                    ? $this->warn('  '.$line)
                    : $this->line('  '.$line);
            }

            if ($result['status'] === Activity::STATUS_FAILED) {
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Done: %d processed, %d failed.', $pending->count(), $failed));

        return $failed > 0 && $failed === $pending->count() ? self::FAILURE : self::SUCCESS;
    }

    private function resolveDevice($user, ?string $flag): ?Device
    {
        if ($flag !== null && $flag !== '') {
            $device = Device::query()
                ->where('name', 'like', '%'.$flag.'%')
                ->first();

            if ($device === null) {
                $this->error("No device matches \"{$flag}\".");

                return null;
            }

            return $device;
        }

        return $user->selectedDevice ?? Device::default();
    }

    /**
     * @return int|null number imported, null on hard failure
     */
    private function import($user, int $days): ?int
    {
        $service = new MyWhooshService($user);

        try {
            $activities = $service->call(fn ($client) => $client->activitiesWithinDays($days));
        } catch (\Throwable $e) {
            $this->error('MyWhoosh fetch failed: '.$e->getMessage());

            return null;
        }

        if ($activities === []) {
            $this->line("No activities within the last {$days} day(s).");

            return 0;
        }

        foreach ($activities as $a) {
            Activity::query()->updateOrCreate(
                ['user_id' => $user->id, 'mywhoosh_id' => $a->id],
                [
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
                ],
            );
        }

        return count($activities);
    }
}
