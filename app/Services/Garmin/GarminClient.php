<?php

namespace App\Services\Garmin;

use App\Models\GarminAccount;
use Illuminate\Support\Facades\Http;

/**
 * Uploads FIT files to Garmin Connect. Authenticates with a "DI" OAuth2
 * Bearer token (the replacement for the OAuth1/OAuth2 flow Garmin retired in
 * March 2026), refreshing it via the stored refresh token when needed, with
 * one 401-retry.
 */
final class GarminClient
{
    private const TIMEOUT = 60;

    private ?DiToken $token = null;
    private readonly GarminSsoService $sso;

    public function __construct(private readonly GarminAccount $account, ?GarminSsoService $sso = null)
    {
        $this->sso = $sso ?? new GarminSsoService($account->domain());
    }

    /**
     * Upload a FIT file. Returns response metadata on success.
     *
     * @return array{status: int, body: string}
     *
     * @throws GarminDuplicateException HTTP 409 — already on Garmin.
     * @throws GarminUploadException    other upload failures.
     */
    public function uploadFit(string $bytes, string $filename): array
    {
        $result = $this->uploadAttempt($bytes, $filename);

        if ($result[0] === 409) {
            throw new GarminDuplicateException('duplicate activity (already uploaded to Garmin)');
        }

        if ($result[0] >= 400) {
            throw GarminUploadException::fromStatus($result[0], $result[1]);
        }

        $this->assertNoImportFailures($result[1]);

        return [
            'status' => $result[0],
            'body' => $result[1],
        ];
    }

    public function refreshToken(): void
    {
        $this->refresh();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{0: int, 1: string} [status, body]
     */
    private function uploadAttempt(string $bytes, string $filename, int $attempt = 0): array
    {
        $token = $this->ensureToken();

        $response = Http::withOptions(['timeout' => self::TIMEOUT])
            ->withHeaders(GarminSsoService::nativeHeaders([
                'Authorization' => 'Bearer '.$token->accessToken,
                'Accept' => 'application/json',
            ]))
            ->attach('file', $bytes, $filename)
            ->post(sprintf('https://connectapi.%s/upload-service/upload', $this->account->domain()));

        if ($response->status() === 401 && $attempt === 0) {
            // Token might be stale despite not being expired.
            $this->refresh();

            return $this->uploadAttempt($bytes, $filename, 1);
        }

        return [$response->status(), $response->body()];
    }

    private function ensureToken(): DiToken
    {
        if ($this->token === null) {
            $stored = $this->account->diToken();
            if ($stored === null || ! $stored->isUsable()) {
                throw new GarminAuthException('Garmin is not connected');
            }

            $this->token = $stored;

            if ($stored->isExpiringWithin()) {
                $this->refresh();
            }
        }

        return $this->token;
    }

    private function refresh(): void
    {
        $stored = $this->account->diToken();
        if ($stored === null || ! $stored->isUsable()) {
            throw new GarminAuthException('no Garmin token on file — reconnect on Connections');
        }

        $fresh = $this->sso->refreshDiToken($stored);
        $this->account->setDiToken($fresh);
        $this->account->save();
        $this->token = $fresh;
    }

    private function assertNoImportFailures(string $body): void
    {
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return;
        }

        $failures = $json['detailedImportResult']['failures'] ?? null;
        if (is_array($failures) && $failures !== []) {
            throw new GarminUploadException('upload reported failures: '.json_encode($failures));
        }
    }
}
