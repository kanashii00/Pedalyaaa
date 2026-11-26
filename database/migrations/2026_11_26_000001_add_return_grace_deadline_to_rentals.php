<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->timestamp('returnGraceStartedAt')->nullable()->after('expiredAt');
            $table->timestamp('returnGraceDeadline')->nullable()->after('returnGraceStartedAt');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['returnGraceStartedAt', 'returnGraceDeadline']);
        });
    }
};
