<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->text('password')->nullable();       // encrypted, only stored on request
            $table->text('oauth1_token')->nullable();   // encrypted JSON, ~1 year validity
            $table->text('oauth2_token')->nullable();   // encrypted JSON, short-lived
            $table->timestamp('oauth2_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garmin_accounts');
    }
};
