<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RFID hardware is not part of the deployed setup: no firmware reports
     * it and no UI reads it, so the column goes rather than lingering as a
     * home for fabricated values. Rental and bicycle records are untouched.
     */
    public function up(): void
    {
        Schema::table('device_status', function (Blueprint $table) {
            $table->dropColumn('rfid');
        });
    }

    public function down(): void
    {
        Schema::table('device_status', function (Blueprint $table) {
            $table->string('rfid')->nullable();
        });
    }
};
