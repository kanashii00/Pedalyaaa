<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bicycles', function (Blueprint $table) {
            $table->decimal('wheelCircumferenceM', 5, 3)->nullable();
        });

        Schema::table('device_status', function (Blueprint $table) {
            $table->decimal('speedKmh', 5, 2)->nullable();
            $table->decimal('wheelCircumferenceM', 5, 3)->nullable();
            $table->unsignedBigInteger('hallPulseCount')->nullable();
            $table->string('speedSensorStatus', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_status', function (Blueprint $table) {
            $table->dropColumn([
                'speedKmh',
                'wheelCircumferenceM',
                'hallPulseCount',
                'speedSensorStatus',
            ]);
        });

        Schema::table('bicycles', function (Blueprint $table) {
            $table->dropColumn('wheelCircumferenceM');
        });
    }
};
