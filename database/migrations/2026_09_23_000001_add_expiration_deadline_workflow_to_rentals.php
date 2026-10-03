<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the rental expiration -> admin-set return deadline -> overdue-return
     * lifecycle: columns tracking when a rental expired, the deadline an
     * administrator granted for returning the bicycle, who set/changed that
     * deadline, and when the deadline passed without a confirmation.
     */
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->timestamp('expiredAt')->nullable()->after('warningSentAt');
            $table->timestamp('returnDeadline')->nullable()->after('expiredAt');
            $table->string('returnDeadlineSetBy')->nullable()->after('returnDeadline');
            $table->timestamp('overdueReturnAt')->nullable()->after('returnDeadlineSetBy');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE rentals MODIFY status ENUM("active","pending","awaiting_return","completed","cancelled","overdue","returned","expired","overdue_return") NOT NULL DEFAULT "pending"');
        } elseif (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->setPostgresStatusConstraint(['active', 'pending', 'awaiting_return', 'completed', 'cancelled', 'overdue', 'returned', 'expired', 'overdue_return']);
        } else {
            Schema::table('rentals', function (Blueprint $table) {
                $table->enum('status', ['active', 'pending', 'awaiting_return', 'completed', 'cancelled', 'overdue', 'returned', 'expired', 'overdue_return'])
                    ->default('pending')
                    ->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn([
                'expiredAt',
                'returnDeadline',
                'returnDeadlineSetBy',
                'overdueReturnAt',
            ]);
        });

        DB::table('rentals')->where('status', 'overdue_return')->update(['status' => 'overdue']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE rentals MODIFY status ENUM("active","pending","awaiting_return","completed","cancelled","overdue","returned","expired") NOT NULL DEFAULT "pending"');
        } elseif (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->setPostgresStatusConstraint(['active', 'pending', 'awaiting_return', 'completed', 'cancelled', 'overdue', 'returned', 'expired']);
        }
    }

    /**
     * PostgreSQL represents Laravel enums as varchar columns with CHECK constraints.
     *
     * @param  array<int, string>  $statuses
     */
    private function setPostgresStatusConstraint(array $statuses): void
    {
        DB::statement('ALTER TABLE "rentals" DROP CONSTRAINT IF EXISTS "rentals_status_check"');

        $allowedStatuses = collect($statuses)
            ->map(fn (string $status): string => DB::connection()->getPdo()->quote($status))
            ->implode(', ');

        DB::statement('ALTER TABLE "rentals" ADD CONSTRAINT "rentals_status_check" CHECK ("status" IN ('.$allowedStatuses.'))');
    }
};