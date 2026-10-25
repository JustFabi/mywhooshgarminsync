<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('manufacturer')->default(1); // Garmin
            $table->unsignedSmallInteger('product_id');               // Garmin product code
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['manufacturer', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
