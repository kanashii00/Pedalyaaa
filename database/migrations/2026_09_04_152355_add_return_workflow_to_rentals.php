<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the "awaiting_return" lifecycle status plus the return-workflow
     * tracking fields used by the Process Return confirmation flow.
     */
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->timestamp('returnRequestedAt')->nullable()->after('overdueAt');
            $table->string('returnCondition')->nullable()->after('returnRequestedAt');
            $table->string('returnInspectedBy')->nullable()->after('returnCondition');
            $table->timestamp('returnProcessedAt')->nullable()->after('returnInspectedBy');
            $table->text('returnNote')->nullable()->after('returnProcessedAt');
            $table->decimal('overdueFee', 10, 2)->nullable()->after('returnNote');
            $table->decimal('finalFee', 10, 2)->nullable()->after('overdueFee');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE rentals MODIFY status ENUM("active","pending","awaiting_return","completed","cancelled","overdue","returned","expired") NOT NULL DEFAULT "pending"');
        } elseif (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->setPostgresStatusConstraint(['active', 'pending', 'awaiting_return', 'completed', 'cancelled', 'overdue', 'returned', 'expired']);
        } else {
            Schema::table('rentals', function (Blueprint $table) {
                $table->enum('status', ['active', 'pending', 'awaiting_return', 'completed', 'cancelled', 'overdue', 'returned', 'expired'])
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
                'returnRequestedAt',
                'returnCondition',
                'returnInspectedBy',
                'returnProcessedAt',
                'returnNote',
                'overdueFee',
                'finalFee',
            ]);
        });

        DB::table('rentals')->where('status', 'awaiting_return')->update(['status' => 'pending']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE rentals MODIFY status ENUM("active","pending","completed","cancelled","overdue","returned","expired") NOT NULL DEFAULT "pending"');
        } elseif (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->setPostgresStatusConstraint(['active', 'pending', 'completed', 'cancelled', 'overdue', 'returned', 'expired']);
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
