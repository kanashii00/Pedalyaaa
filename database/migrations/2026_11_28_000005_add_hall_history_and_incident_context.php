<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_status', function (Blueprint $table) {
            $table->timestamp('lastHallPulseAt')->nullable();
            $table->unsignedInteger('pulseIntervalMs')->nullable();
            $table->decimal('estimatedDistanceKm', 14, 4)->nullable();
            $table->string('movementStatus', 16)->nullable();
        });

        Schema::table('accidents', function (Blueprint $table) {
            $table->string('deviceEventId', 64)->nullable()->unique();
            $table->json('sensorContext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accidents', function (Blueprint $table) {
            $table->dropUnique(['deviceEventId']);
            $table->dropColumn(['deviceEventId', 'sensorContext']);
        });

        Schema::table('device_status', function (Blueprint $table) {
            $table->dropColumn([
                'lastHallPulseAt',
                'pulseIntervalMs',
                'estimatedDistanceKm',
                'movementStatus',
            ]);
        });
    }
};
