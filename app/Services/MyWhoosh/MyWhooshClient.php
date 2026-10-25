<?php

namespace App\Services\MyWhoosh;

use App\Models\MyWhooshAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * API client for MyWhoosh, ported from mywoosh2garmin's mywhoosh/client.go.
 *
 *  - Login:            POST https://services.mywhoosh.com/http-service/api/login
 *  - Activities:       POST https://service14.mywhoosh.com/v2/rider/profile/activities
 *  - FIT download:     POST .../rider/profile/download-activity-file → presigned S3 URL → GET
 */
final class MyWhooshClient
{
    private const LOGIN_URL = 'https://services.mywhoosh.com/http-service/api/login';
    private const BASE_URL = 'https://service14.mywhoosh.com/v2/';
    private const TIMEOUT = 30;

    public function __construct(private readonly string $token) {}

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    /**
     * Authenticate and return a new client plus session data.
     *
     * @return array{client: self, accessToken: string, whooshId: string}
     */
    public static function login(string $email, string $password): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->post(self::LOGIN_URL, [
                'Username' => $email,
                'Password' => $password,
                'Platform' => 'Android',
                'Action' => 1001,
                'CorrelationId' => self::uuid(),
                'DeviceId' => self::uuid(),
                'Authorization' => '',
            ]);

        if ($response->status() >= 400) {
            throw new MyWhooshException(sprintf(
                'login failed (HTTP %d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        $data = $response->json() ?? [];

        if (! ($data['Success'] ?? false)) {
            throw new MyWhooshException('login failed: '.($data['Message'] ?? 'unknown error'));
        }

        $token = $data['AccessToken'] ?? '';
        if ($token === '' || $token === null) {
            throw new MyWhooshException('login failed: no access token in response');
        }

        return [
            'client' => new self((string) $token),
            'accessToken' => (string) $token,
            'whooshId' => (string) ($data['WhooshId'] ?? ''),
        ];

    }

    /**
     * Build a client from a persisted account.
     */
    public static function fromAccount(MyWhooshAccount $account): self
    {
        $token = (string) $account->access_token;

        if ($token === '') {
            throw new MyWhooshAuthException('no MyWhoosh session on file — connect first');
        }

        return new self($token);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function isAuthenticated(): bool
    {
        return $this->token !== '';
    }

    // ------------------------------------------------------------------
    // Activities
    // ------------------------------------------------------------------

    /**
     * Fetch one page of activities, newest first.
     *
     * @return array{results: array<int, MyWhooshActivity>, totalPages: int}
     */
    public function activitiesPage(int $page = 1): array
    {
        $response = $this->authorizedRequest('rider/profile/activities', [
            'sortDate' => 'DESC',
            'page' => $page,
        ]);

        $data = $response->json('data') ?? [];
        $results = [];
        foreach ($data['results'] ?? [] as $item) {
            if (is_array($item) && ($item['id'] !== null && $item['id'] !== '')) {
                $results[] = MyWhooshActivity::fromArray($item);
            }
        }

        return [
            'results' => $results,
            'totalPages' => (int) ($data['totalPages'] ?? 1),
        ];
    }

    /**
     * Fetch activities, walking all pages. When $days is given, stop as soon
     * as the page falls below the cutoff (the API sorts newest first).
     *
     * @return array<int, MyWhooshActivity>
     */
    public function activitiesWithinDays(?int $days = null): array
    {
        $cutoff = $days !== null ? now()->subDays($days)->timestamp : null;
        $all = [];
        $page = 1;

        while (true) {
            ['results' => $results, 'totalPages' => $totalPages] = $this->activitiesPage($page);

            foreach ($results as $activity) {
                $unix = $activity->startAt()?->getTimestamp()
                    ?? ($activity->date !== null ? (int) $activity->date : null);

                if ($cutoff !== null && $unix !== null && $unix < $cutoff) {
                    // Sorted DESC: everything after is older — stop.
                    return $all;
                }

                $all[] = $activity;
            }

            if ($page >= max($totalPages, 1)) {
                break;
            }

            $page++;
        }

        return $all;
    }

    // ------------------------------------------------------------------
    // FIT download
    // ------------------------------------------------------------------

    public function downloadFit(string $activityFileId): string
    {
        $response = $this->authorizedRequest('rider/profile/download-activity-file', ['fileId' => $activityFileId]);

        $url = $response->json('data');
        if (! is_string($url) || $url === '') {
            throw new MyWhooshException('no download URL in response');
        }

        $fit = Http::timeout(self::TIMEOUT)->get($url);
        if ($fit->status() >= 400) {
            throw new MyWhooshException('FIT download failed (HTTP '.$fit->status().')');
        }

        return $fit->body();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function authorizedRequest(string $path, array $payload): Response
    {
        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->withToken($this->token)
            ->post(self::BASE_URL.$path, $payload);

        if ($response->status() === 401 || $response->status() === 403) {
            throw MyWhooshAuthException::unauthorized($response->status());
        }

        if ($response->status() >= 400) {
            throw new MyWhooshException(sprintf(
                '%s request failed (HTTP %d): %s',
                $path,
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        return $response;
    }

    private static function uuid(): string
    {
        return class_exists(Uuid::class) ? Uuid::uuid4()->toString() : self::fallbackUuid();
    }

    private static function fallbackUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
