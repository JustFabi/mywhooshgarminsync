<?php

namespace App\Services\Garmin;

use App\Models\GarminAccount;
use App\Models\User;

/**
 * Per-user Garmin facade: resolves the connected account, performs SSO
 * logins and keeps persisted credentials in sync.
 */
final class GarminService
{
    public function __construct(private readonly User $user) {}

    public function client(): GarminClient
    {
        $account = $this->user->garmin;
        if ($account === null) {
            throw new GarminAuthException('Garmin is not connected');
        }

        return new GarminClient($account);
    }

    public function account(): GarminAccount
    {
        $account = $this->user->garmin;
        if ($account === null) {
            throw new GarminAuthException('Garmin is not connected');
        }

        return $account;
    }

    /**
     * Full SSO login and token storage (optionally remembering the password
     * so future OAuth1 expiries can be re-logged-in automatically).
     */
    public function login(string $email, string $password, bool $rememberPassword = false): void
    {
        $account = $this->user->garmin()->updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'email' => $email,
                'password' => $rememberPassword ? $password : $this->user->garmin?->password,
                'connected_at' => now(),
            ],
        );

        [$oauth1, $oauth2] = (new GarminSsoService($this->domainFor($account)))->login($email, $password);

        $account->oauth1_token = $oauth1->toArray();
        $account->setOAuth2($oauth2);
        $account->save();
    }

    private function domainFor(GarminAccount $account): string
    {
        return $account->oauth1()?->domain ?: 'garmin.com';
    }

    public function disconnect(): void
    {
        $this->user->garmin()?->delete();
    }
}
