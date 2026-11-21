<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade accidents table to support professional security incident management,
     * accurate rider/rental association, resolution notes, and investigation status.
     */
    public function up(): void
    {
        // 1. Widen severity column on MySQL if needed so 'critical', 'warning', 'informational', etc. are accepted
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `accidents` MODIFY COLUMN `severity` VARCHAR(32) NOT NULL DEFAULT 'minor'");
        }

        // 2. Add incident management and rental/rider association columns
        Schema::table('accidents', function (Blueprint $table) {
            if (!Schema::hasColumn('accidents', 'rentalId')) {
                $table->foreignId('rentalId')->nullable()->after('bicycleId')->constrained('rentals')->nullOnDelete();
            }

            if (!Schema::hasColumn('accidents', 'riderId')) {
                $table->foreignId('riderId')->nullable()->after('rentalId')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('accidents', 'riderName')) {
                $table->string('riderName')->nullable()->after('riderId');
            }

            if (!Schema::hasColumn('accidents', 'investigationStatus')) {
                $table->string('investigationStatus')->default('pending')->after('status');
                $table->index('investigationStatus');
            }

            if (!Schema::hasColumn('accidents', 'resolutionNotes')) {
                $table->text('resolutionNotes')->nullable()->after('actionTaken');
            }

            if (!Schema::hasColumn('accidents', 'resolvedBy')) {
                $table->foreignId('resolvedBy')->nullable()->after('resolutionNotes')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('accidents', 'resolvedAt')) {
                $table->timestamp('resolvedAt')->nullable()->after('resolvedBy');
            }

            if (!Schema::hasColumn('accidents', 'lastEscalatedAt')) {
                $table->timestamp('lastEscalatedAt')->nullable()->after('resolvedAt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accidents', function (Blueprint $table) {
            $foreignKeys = ['rentalId', 'riderId', 'resolvedBy'];
            foreach ($foreignKeys as $fk) {
                if (Schema::hasColumn('accidents', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }

            $columns = [
                'rentalId',
                'riderId',
                'riderName',
                'investigationStatus',
                'resolutionNotes',
                'resolvedBy',
                'resolvedAt',
                'lastEscalatedAt',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('accidents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
