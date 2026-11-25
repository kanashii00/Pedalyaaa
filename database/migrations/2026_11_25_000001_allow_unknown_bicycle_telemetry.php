<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bicycles', function (Blueprint $table) {
            $table->integer('batteryLevel')->nullable()->default(null)->change();
            $table->enum('lockStatus', ['locked', 'unlocked'])->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('bicycles')->whereNull('batteryLevel')->orWhereNull('lockStatus')->exists()) {
            throw new RuntimeException(
                'Cannot restore required bicycle telemetry columns while unknown readings exist.'
            );
        }

        Schema::table('bicycles', function (Blueprint $table) {
            $table->integer('batteryLevel')->nullable(false)->default(100)->change();
            $table->enum('lockStatus', ['locked', 'unlocked'])->nullable(false)->default('locked')->change();
        });
    }
};
