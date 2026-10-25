<?php

namespace App\Support;

use App\Models\User;

/**
 * Serializes the connection status of both services for Inertia pages.
 */
final class ConnectionState
{
    /** @return array{email: string, connected_at: ?string, remembered: bool, whoosh_id: string}|null */
    public static function myWhoosh(User $user): ?array
    {
        $account = $user->myWhoosh;
        if ($account === null) {
            return null;
        }

        return [
            'email' => $account->email,
            'connected_at' => optional($account->connected_at)->toIso8601String(),
            'remembered' => $account->canAutoRelogin(),
            'whoosh_id' => (string) $account->whoosh_id,
        ];
    }

    /** @return array{email: string, connected_at: ?string, remembered: bool, expires_at: ?string}|null */
    public static function garmin(User $user): ?array
    {
        $account = $user->garmin;
        if ($account === null) {
            return null;
        }

        return [
            'email' => $account->email,
            'connected_at' => optional($account->connected_at)->toIso8601String(),
            'remembered' => $account->canAutoRelogin(),
            'expires_at' => optional($account->oauth2_expires_at)->toIso8601String(),
        ];
    }
}
