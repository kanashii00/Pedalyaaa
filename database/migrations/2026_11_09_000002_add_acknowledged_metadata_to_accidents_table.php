<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accidents', function (Blueprint $table) {
            if (!Schema::hasColumn('accidents', 'acknowledgedBy')) {
                $table->foreignId('acknowledgedBy')->nullable()->after('acknowledged')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('accidents', 'acknowledgedAt')) {
                $table->timestamp('acknowledgedAt')->nullable()->after('acknowledgedBy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accidents', function (Blueprint $table) {
            if (Schema::hasColumn('accidents', 'acknowledgedBy')) {
                $table->dropConstrainedForeignId('acknowledgedBy');
            }
            if (Schema::hasColumn('accidents', 'acknowledgedAt')) {
                $table->dropColumn('acknowledgedAt');
            }
        });
    }
};
