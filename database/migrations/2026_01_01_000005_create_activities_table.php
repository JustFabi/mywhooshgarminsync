<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mywhoosh_id');
            $table->string('activity_file_id')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->decimal('distance_km', 10, 3)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('watt', 8, 1)->nullable();
            $table->unsignedTinyInteger('heartrate')->nullable();
            $table->decimal('elevation_m', 10, 1)->nullable();
            $table->decimal('watt_per_kg', 6, 2)->nullable();
            $table->string('sport_type')->nullable();
            $table->string('route_name')->nullable();
            $table->string('status')->default('pending'); // pending | synced | failed
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('fit_fixed_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'mywhoosh_id']);
            $table->index(['user_id', 'status']);
            $table->index('start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
