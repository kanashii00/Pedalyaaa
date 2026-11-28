<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bicycles')
            ->where('serialNumber', 'PDY-001')
            ->update(['wheelCircumferenceM' => 2.086]);
    }

    public function down(): void
    {
        DB::table('bicycles')
            ->where('serialNumber', 'PDY-001')
            ->where('wheelCircumferenceM', 2.086)
            ->update(['wheelCircumferenceM' => null]);
    }
};
