<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garmin retired its OAuth1/OAuth2 "mobile auth" flow (March 2026); tokens
 * are now DI OAuth2 tokens exchanged at diauth.garmin.com. Adds the DI token
 * columns and drops the obsolete OAuth columns.
 *
 * Guarded so it works on both the slim fresh-install schema (which never had
 * the OAuth columns) and the legacy schema (which does).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('garmin_accounts', 'di_token')) {
            Schema::table('garmin_accounts', function (Blueprint $table) {
                $table->text('di_token')->nullable();
                $table->timestamp('di_expires_at')->nullable();
            });
        }

        if (Schema::hasColumn('garmin_accounts', 'oauth1_token')) {
            Schema::table('garmin_accounts', function (Blueprint $table) {
                $table->dropColumn(['oauth1_token', 'oauth2_token', 'oauth2_expires_at']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('garmin_accounts', 'oauth1_token')) {
            Schema::table('garmin_accounts', function (Blueprint $table) {
                $table->text('oauth1_token')->nullable();
                $table->text('oauth2_token')->nullable();
                $table->timestamp('oauth2_expires_at')->nullable();
            });
        }

        if (Schema::hasColumn('garmin_accounts', 'di_token')) {
            Schema::table('garmin_accounts', function (Blueprint $table) {
                $table->dropColumn(['di_token', 'di_expires_at']);
            });
        }
    }
};
