<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ride extensions: the counters, the running extra-time fee and the full
     * per-extension log a receipt prints so an administrator or staff member
     * can see that a ride was extended instead of reading it as overdue.
     *
     * `extensions` stores one entry per extension:
     *   n, minutes, fee, chargedHours, at, newExpectedEnd, by
     */
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->unsignedInteger('extensionCount')->default(0)->after('chargedHours');
            $table->unsignedInteger('extendedMinutes')->default(0)->after('extensionCount');
            $table->decimal('extensionFee', 10, 2)->default(0)->after('extendedMinutes');
            $table->json('extensions')->nullable()->after('extensionFee');
            $table->timestamp('lastExtendedAt')->nullable()->after('extensions');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn([
                'extensionCount',
                'extendedMinutes',
                'extensionFee',
                'extensions',
                'lastExtendedAt',
            ]);
        });
    }
};
