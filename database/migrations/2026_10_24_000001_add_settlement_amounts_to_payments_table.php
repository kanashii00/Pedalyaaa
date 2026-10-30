<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // The final rental charge can be higher than the amount that was
            // actually collected when the rental started (for example a GCash
            // base payment plus a late-return penalty). Keep the two figures
            // explicit so settlement never pretends the extra amount was paid.
            $table->decimal('latePenalty', 12, 2)->default(0)->after('totalAmount');
            $table->decimal('amountPaid', 12, 2)->default(0)->after('latePenalty');
            $table->decimal('balanceDue', 12, 2)->default(0)->after('amountPaid');
        });

        // Existing paid records have already collected their recorded total.
        DB::table('payments')
            ->where('status', 'paid')
            ->update([
                'amountPaid' => DB::raw('totalAmount'),
                'balanceDue' => 0,
            ]);

        // Existing pending/processing records have collected nothing yet.
        DB::table('payments')
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'balanceDue' => DB::raw('totalAmount'),
            ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['latePenalty', 'amountPaid', 'balanceDue']);
        });
    }
};
