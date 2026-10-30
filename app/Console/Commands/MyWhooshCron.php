<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Device;
use App\Services\Garmin\GarminAuthException;
use App\Services\Garmin\GarminClient;
use App\Services\Garmin\GarminService;
use App\Services\MyWhoosh\MyWhooshAuthException;
use App\Services\MyWhoosh\MyWhooshService;
use App\Services\Sync\ActivitySyncService;
use App\Support\EnvFile;
use App\Support\GarminDevices;
use App\Support\LocalUser;
use Illuminate\Console\Command;

/**
 * Unattended sync for cron jobs: credentials come from the environment
 * (this app's .env, the process environment, or via --env-file=KEYVALUE),
 * already-known activities are tracked by their MyWhoosh id, and only
 * activities that are not synced yet are pushed to Garmin.
 *
 *   php artisan mywhoosh:cron
 *   php artisan mywhoosh:cron --env-file=/etc/mywhoosh-sync.env --days=7
 *   php artisan mywhoosh:cron --watch="fenix 7" --fetch-only
 *
 * Exit codes: 0 = nothing pending or fully synced, 1 = configuration /
 * connection error or every upload failed. Partial failures still exit 0
 * (they retry on the next run); use -v or the log lines to spot them.
 */
class MyWhooshCron extends Command
{
    protected $signature = 'mywhoosh:cron
        {--env-file= : Optional KEY=VALUE file providing the credentials (otherwise this app\'s .env / environment applies)}
        {--days=7 : Import activities from this many days back}
        {--watch= : Match a catalog watch by name fragment (default: selected/default watch)}
        {--fetch-only : Import activities but don\'t upload}
        {--upload-only : Skip importing, upload pending activities only}';

    protected $description = 'Unattended MyWhoosh → Garmin sync (env-provided credentials, only unsynced activities)';

    public function handle(): int
    {
        GarminDevices::seed();

        if ($path = $this->option('env-file')) {
            try {
                EnvFile::apply(EnvFile::read((string) $path));
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $myWhooshEmail = (string) env('MYWHOOSH_EMAIL', '');
        $myWhooshPassword = (string) env('MYWHOOSH_PASSWORD', '');
        $garminEmail = (string) env('GARMIN_EMAIL', '');
        $garminPassword = (string) env('GARMIN_PASSWORD', '');

        $user = LocalUser::get();

        $device = $this->resolveDevice($user, $this->option('watch'));
        if ($device === null) {
            $this->error('No watch matches "'.($this->option('watch') ?: '').'".');

            return self::FAILURE;
        }

        $this->info('Spoof watch: '.$device->name.' (product '.$device->product_id.')');

        // ---- ensure MyWhoosh session ---------------------------------
        $myWhoosh = new MyWhooshService($user);
        if ($this->myWhooshNeedsLogin($user, $myWhooshEmail)) {
            if ($myWhooshEmail === '' || $myWhooshPassword === '') {
                $this->error('MyWhoosh not connected — set MYWHOOSH_EMAIL and MYWHOOSH_PASSWORD.');

                return self::FAILURE;
            }

            $myWhoosh->login($myWhooshEmail, $myWhooshPassword, true);
        }

        // ---- import new activities -----------------------------------
        $newCount = 0;
        $knownCount = 0;

        if (! $this->option('upload-only')) {
            $days = (int) $this->option('days');

            try {
                $activities = $this->callMyWhoosh($myWhoosh, $user, $myWhooshEmail, $myWhooshPassword, $days);
            } catch (\Throwable $e) {
                $this->error('MyWhoosh import failed: '.$e->getMessage());

                return self::FAILURE;
            }

            foreach ($activities as $a) {
                $activity = $this->upsertActivity($user, $a);
                $activity->wasRecentlyCreated ? $newCount++ : $knownCount++;
            }

            $this->line("Imported from the last {$days} day(s): {$newCount} new, {$knownCount} already tracked.");
        }

        if ($this->option('fetch-only')) {
            return self::SUCCESS;
        }

        // ---- ensure Garmin tokens ------------------------------------
        $pending = Activity::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Activity::STATUS_PENDING, Activity::STATUS_FAILED])
            ->orderBy('start_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->line('Nothing pending — all activities are already on Garmin.');

            return self::SUCCESS;
        }

        try {
            $this->ensureGarmin($user, (string) $garminEmail, (string) $garminPassword);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ---- upload ---------------------------------------------------
        $sync = new ActivitySyncService;
        $results = $this->upload($sync, $device, $pending);   // activityId => [status, message]

        // One automatic relogin + retry when Garmin rejects the stored
        // session (expired/revoked refresh token, re-authenticated device).
        $authFailures = $this->filterGarminAuthFailures($results);
        if ($authFailures !== [] && $garminEmail !== '' && $garminPassword !== '') {
            $this->warn('Garmin session rejected — logging in again with the configured credentials and retrying once…');

            try {
                (new GarminService($user))->login($garminEmail, $garminPassword, true);
            } catch (GarminAuthException $e) {
                $this->error('Garmin re-login failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $retry = $pending->whereIn('id', array_keys($authFailures));
            $results = $this->upload($sync, $device, $retry)
                + $this->upload($sync, $device, $pending->whereNotIn('id', array_keys($authFailures)));
        }

        $synced = 0;
        $failed = [];

        foreach ($results as $activityId => [$status, $message]) {
            $status === Activity::STATUS_SYNCED ? $synced++ : $failed[] = $activityId;
            $this->line(sprintf(
                '  [%s] #%s — %s',
                $status === Activity::STATUS_SYNCED ? 'OK' : 'FAIL',
                $activityId,
                $message,
            ));
        }

        $this->newLine();
        $this->line("{$synced} synced, ".count($failed).' failed.');

        return count($failed) === count($results) && count($results) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function myWhooshNeedsLogin($user, string $email): bool
    {
        $account = $user->myWhoosh;

        return $account === null
            || ! $account->hasAccessToken()
            || ($email !== '' && $account->email !== $email);
    }

    /**
     * Fetch the activity list, transparently re-authenticating with the
     * configured credentials when the stored session is rejected.
     */
    private function callMyWhoosh(MyWhooshService $service, $user, string $email, string $password, int $days): array
    {
        try {
            return $service->call(fn ($client) => $client->activitiesWithinDays($days));
        } catch (MyWhooshAuthException $e) {
            if ($email === '' || $password === '') {
                throw $e;
            }

            $service->login($email, $password, true);

            return $service->call(fn ($client) => $client->activitiesWithinDays($days));
        }
    }

    private function upsertActivity($user, $a): Activity
    {
        return Activity::query()->updateOrCreate(
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

    /**
     * Make sure a usable Garmin session exists: a fresh DI-token login when
     * nothing usable is stored or the configured account changed, otherwise
     * a token refresh (only when the access token is about to expire).
     *
     * @throws \RuntimeException on missing configuration
     */
    private function ensureGarmin($user, string $email, string $password): void
    {
        $account = $user->garmin()->first();

        $needsLogin = $account === null
            || ! $account->hasDiToken()
            || ($email !== '' && $account->email !== $email);

        if ($needsLogin) {
            if ($email === '' || $password === '') {
                throw new \RuntimeException('Garmin not connected — set GARMIN_EMAIL and GARMIN_PASSWORD.');
            }

            (new GarminService($user))->login($email, $password, true);

            return;
        }

        $token = $account->diToken();
        if ($token === null || ! $token->isExpiringWithin()) {
            return; // token is fresh enough; client refreshes itself later if needed
        }

        try {
            (new GarminClient($account))->refreshToken();
        } catch (GarminAuthException) {
            if ($email === '' || $password === '') {
                throw new \RuntimeException('Garmin token expired — set GARMIN_EMAIL and GARMIN_PASSWORD to re-login automatically.');
            }

            (new GarminService($user))->login($email, $password, true);
        }
    }

    /**
     * Upload a batch synchronously, printing one terse line per activity.
     *
     * @param  \Illuminate\Support\Collection<int, Activity>  $activities
     * @return array<int, array{0: string, 1: string}> activityId => [status, message]
     */
    private function upload(ActivitySyncService $sync, Device $device, $activities): array
    {
        $results = [];

        foreach ($activities as $activity) {
            $result = $sync->sync($activity, $device);
            $results[$activity->id] = [$result['status'], $result['message']];
        }

        return $results;
    }

    /**
     * Pick out results whose failure looks like a Garmin session problem
     * rather than a data/upload issue — those are worth one relogin retry.
     *
     * @param  array<int, array{0: string, 1: string}>  $results
     * @return array<int, array{0: string, 1: string}>
     */
    private function filterGarminAuthFailures(array $results): array
    {
        return array_filter($results, function (array $result): bool {
            [$status, $message] = $result;

            if ($status !== Activity::STATUS_FAILED) {
                return false;
            }

            $message = strtolower($message);

            return str_contains($message, 'garmin') &&
                (str_contains($message, 'token') || str_contains($message, 'connected') || str_contains($message, '401'));
        });
    }

    private function resolveDevice($user, ?string $flag): ?Device
    {
        if ($flag !== null && $flag !== '') {
            return Device::query()->where('name', 'like', '%'.$flag.'%')->first();
        }

        return $user->selectedDevice ?? Device::default();
    }
}
