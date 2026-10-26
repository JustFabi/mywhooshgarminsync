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
     * so future sessions can be re-established automatically).
     *
     * The login runs BEFORE any persistence: a failed attempt must not leave
     * a tokenless account row behind (it would masquerade as "connected").
     */
    public function login(string $email, string $password, bool $rememberPassword = false): void
    {
        $token = (new GarminSsoService)->login($email, $password);

        $this->user->garmin()->updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'email' => $email,
                'password' => $rememberPassword && $password !== '' ? $password : $this->user->garmin?->password,
                'di_token' => $token->toArray(),
                'di_expires_at' => date('c', $token->expiresAt),
                'connected_at' => now(),
            ],
        );
    }

    public function disconnect(): void
    {
        $this->user->garmin()?->delete();
    }
}
