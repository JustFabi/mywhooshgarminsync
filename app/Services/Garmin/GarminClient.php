<?php

namespace App\Services\Garmin;

use App\Models\GarminAccount;

/**
 * Uploads FIT files to Garmin Connect, ported from mywoosh2garmin's
 * garmin/client.go. Manages OAuth2 expiry and one 401-retry.
 */
final class GarminClient
{
    private const API_USER_AGENT = 'GCM-iOS-5.19.1.2';
    private const TIMEOUT = 60;

    private ?OAuth2Token $oauth2 = null;

    public function __construct(private readonly GarminAccount $account, private readonly GarminSsoService $sso = new GarminSsoService()) {}

    /**
     * Resolve a valid OAuth2 token, refreshing from the stored OAuth1 token
     * when needed. Throws when nothing usable exists.
     */
    public function ensureOAuth2(): OAuth2Token
    {
        $this->oauth2 = $this->account->oauth2();

        if ($this->oauth2 === null) {
            throw new GarminAuthException('Garmin is not connected');
        }

        if ($this->oauth2->isExpired()) {
            $this->refresh();
        }

        return $this->oauth2;
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
        $oauth2 = $this->ensureOAuth2();
        $domain = $this->account->domain();

        $response = Http::withOptions(['timeout' => self::TIMEOUT])
            ->withToken($oauth2->accessToken)
            ->attach('file', $bytes, $filename)
            ->post(sprintf('https://connectapi.%s/upload-service/upload', $domain));

        if ($response->status() === 401 && $attempt === 0) {
            // Token might be stale despite not being expired.
            $this->refresh();

            return $this->uploadAttempt($bytes, $filename, 1);
        }

        return [$response->status(), $response->body()];
    }

    private function refresh(): void
    {
        $oauth1 = $this->account->oauth1();
        if ($oauth1 === null || $oauth1->oauthToken === '') {
            throw new GarminAuthException('no Garmin OAuth1 token on file — reconnect on Connections');
        }

        $fresh = $this->sso->exchange($oauth1);
        $this->account->setOAuth2($fresh);
        $this->account->save();
        $this->oauth2 = $fresh;
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
