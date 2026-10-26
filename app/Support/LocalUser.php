<?php

namespace App\Support;

use App\Models\User;

/**
 * The single, implicit local user.
 *
 * This tool has no authentication: anyone who can reach the app shares one
 * workspace. The database schema (activities, connected accounts, selected
 * watch) is keyed on a user row, so exactly one row is created on demand
 * and never exposed through any login or registration flow.
 */
final class LocalUser
{
    public static function get(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'local@localhost'],
            ['name' => 'Local'],
        );
    }
}
