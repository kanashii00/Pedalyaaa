<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the per-rental late-return penalty rule issued together with an
     * administrator-set return deadline: the penalty type (fixed amount,
     * per hour, or per minute), its value, and a running snapshot
     * (penaltyAccrued) recorded when the deadline passes and recomputed when
     * the return is finally confirmed.
     */
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->string('penaltyType')->nullable()->after('returnDeadlineSetBy');
            $table->decimal('penaltyValue', 10, 2)->nullable()->after('penaltyType');
            $table->decimal('penaltyAccrued', 10, 2)->nullable()->after('penaltyValue');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['penaltyType', 'penaltyValue', 'penaltyAccrued']);
        });
    }
};