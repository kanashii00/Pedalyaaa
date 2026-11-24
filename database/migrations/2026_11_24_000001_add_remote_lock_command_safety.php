<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->timestamp('expiresAt')->nullable()->after('executedAt');
            $table->timestamp('acknowledgedAt')->nullable()->after('expiresAt');
            $table->timestamp('supersededAt')->nullable()->after('acknowledgedAt');
            $table->index(['bicycleId', 'status', 'id']);
        });
        Schema::table('bicycles', function (Blueprint $table) {
            $table->timestamp('manualUnlockOverrideUntil')->nullable()->after('lastLockAction');
            $table->foreignId('manualUnlockOverrideBy')->nullable()->after('manualUnlockOverrideUntil')->constrained('users')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('bicycles', function (Blueprint $table) { $table->dropConstrainedForeignId('manualUnlockOverrideBy'); $table->dropColumn('manualUnlockOverrideUntil'); });
        Schema::table('device_commands', function (Blueprint $table) { $table->dropIndex(['bicycleId', 'status', 'id']); $table->dropColumn(['expiresAt', 'acknowledgedAt', 'supersededAt']); });
    }
};
