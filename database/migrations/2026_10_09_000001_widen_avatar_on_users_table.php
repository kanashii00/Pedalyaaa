<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google profile photo URLs (lh3.googleusercontent.com, often with long
     * photo IDs and size parameters) regularly exceed 255 characters.
     * Storing one in the VARCHAR(255) avatar column aborted Google sign-in
     * with "Data too long for column 'avatar'" after the OAuth exchange had
     * already succeeded. TEXT has no such ceiling.
     *
     * Raw SQL is used because doctrine/dbal is not installed; the change is
     * a no-op on SQLite, which does not enforce VARCHAR lengths anyway.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `users` MODIFY `avatar` TEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `users` MODIFY `avatar` VARCHAR(255) NULL');
        }
    }
};
