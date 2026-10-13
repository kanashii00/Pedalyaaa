<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational (non-security) event types backfilled below. The canonical
     * list lives in App\Services\AuditLogger::OPERATIONAL_ACTIONS; it is
     * duplicated here (rather than referenced) so old migrations keep working
     * even if the service mapping changes later.
     */
    private const OPERATIONAL_ACTIONS = [
        'maintenance_created',
        'maintenance_updated',
        'maintenance_status_updated',
        'bicycle_maintenance_auto',
        'accident_acknowledged',
        'theft_alert_acknowledged',
    ];

    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('category')->default('security')->after('action');
            $table->index(['category', 'created_at']);
        });

        DB::table('audit_logs')
            ->whereIn('action', self::OPERATIONAL_ACTIONS)
            ->update(['category' => 'operational']);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['category', 'created_at']);
            $table->dropColumn('category');
        });
    }
};
