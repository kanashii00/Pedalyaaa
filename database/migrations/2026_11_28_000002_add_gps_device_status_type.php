<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_status', function (Blueprint $table) {
            $table->enum('type', ['heartbeat', 'command', 'lock_command', 'gps'])
                ->default('heartbeat')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('device_status')
            ->where('type', 'gps')
            ->update(['type' => 'heartbeat']);

        Schema::table('device_status', function (Blueprint $table) {
            $table->enum('type', ['heartbeat', 'command', 'lock_command'])
                ->default('heartbeat')
                ->change();
        });
    }
};
