<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy installs (pre no-auth refactor) created users.password as NOT NULL.
 * This tool has no login, so nothing ever writes a password — make the column
 * nullable. Fresh installs no longer have the column at all (see the users
 * table migration), so this becomes a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->change();
        });
    }
};
