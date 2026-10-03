<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow theft / boundary-breach alerts to be recorded with the canonical
     * 'theft' type so the Theft Detection module, dashboard counters and
     * incident reports all share one source of truth.
     */
    public function up(): void
    {
        $this->changeAccidentTypes(['accident', 'geofence_breach', 'geofence_alert', 'impact_detected', 'theft']);
    }

    public function down(): void
    {
        $this->changeAccidentTypes(['accident', 'geofence_breach', 'geofence_alert', 'impact_detected']);
    }

    private function changeAccidentTypes(array $types): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "accidents" DROP CONSTRAINT IF EXISTS "accidents_type_check"');

            $allowedTypes = collect($types)
                ->map(fn (string $type): string => DB::connection()->getPdo()->quote($type))
                ->implode(', ');

            DB::statement('ALTER TABLE "accidents" ADD CONSTRAINT "accidents_type_check" CHECK ("type" IN ('.$allowedTypes.'))');

            return;
        }

        Schema::table('accidents', function (Blueprint $table) use ($types) {
            $table->enum('type', $types)
                ->default('accident')
                ->change();
        });
    }
};
