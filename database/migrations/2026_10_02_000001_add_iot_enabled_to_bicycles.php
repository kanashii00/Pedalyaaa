<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which bicycles are actually fitted with controllable hardware.
 *
 * The inventory holds physical units that are parked and not in service. They
 * have no ESP32, no servo and no smart lock, so "locked" on those rows is just
 * the state they happen to be sitting in - there is nothing to command, and a
 * lock command sent to one would sit pending forever waiting for an
 * acknowledgement that can never arrive.
 *
 * iotEnabled is the single source of truth for that question. It gates:
 *   - rider-facing availability (a bicycle without hardware is never rentable),
 *   - every lock/unlock command queued through DeviceCommandService,
 *   - the lock-status and status rewrites that used to be applied unconditionally
 *     when a rental was settled or cancelled.
 *
 * The default is false, so a bicycle is not assumed to be controllable until it
 * has been explicitly provisioned. That is the safe direction: an unprovisioned
 * unit stays out of the rentable fleet instead of being advertised and then
 * failing to unlock.
 *
 * iotEnabled answers "can this unit be commanded?". The companion migration
 * 2026_10_02_000002 adds the `out_of_service` status, which answers "is this
 * unit rentable?". Both are needed: neither alone rules out the failure mode
 * where a bicycle without hardware is advertised to a rider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bicycles', function (Blueprint $table) {
            $table->boolean('iotEnabled')->default(false)->after('lockStatus');
            $table->index('iotEnabled');
        });
    }

    public function down(): void
    {
        Schema::table('bicycles', function (Blueprint $table) {
            $table->dropIndex(['iotEnabled']);
            $table->dropColumn('iotEnabled');
        });
    }
};