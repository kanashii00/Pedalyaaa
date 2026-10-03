<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align `bicycles.condition` with the inspected condition a return records.
 *
 * The Return / End Ride workflow stamps the inspected condition from
 * Rental::$returnCondition onto the bicycle row. That vocabulary is
 * good / fair / damaged / needs_maintenance, but the column was created as
 * good / needs_inspection / damaged, so `fair` and `needs_maintenance` were not
 * representable.
 *
 * On MySQL this was a hard failure: the write was rejected under
 * STRICT_TRANS_TABLES, which rolled back the whole settlement transaction. A
 * bicycle returned in Fair condition therefore could never be released to
 * available, and a Needs Maintenance return could never route to maintenance.
 *
 * The tests did not catch it because SQLite only materialises an enum as a CHECK
 * constraint when the column is rebuilt. `condition` had never been altered, so
 * the test database accepted any value while production rejected two of them.
 * That is why the SQLite branch below is not optional: rebuilding the column is
 * what makes the test database enforce the same values production does.
 *
 * `needs_inspection` is retained rather than dropped: it is a valid stored value
 * even though the return workflow no longer produces it, and discarding existing
 * rows would lose information the inventory may still be reporting on.
 *
 * The values are written out here rather than read from Rental::CONDITIONS. A
 * migration has to describe the schema as it was at this point in history; if it
 * followed a model constant instead, changing the workflow vocabulary later
 * would silently change what an old migration builds on a fresh install.
 */
return new class extends Migration
{
    private const LEGACY_VALUES = ['good', 'needs_inspection', 'damaged'];

    private const VALUES = ['good', 'needs_inspection', 'damaged', 'fair', 'needs_maintenance'];

    public function up(): void
    {
        $this->setEnum(self::VALUES, 'good');
    }

    public function down(): void
    {
        // Rows that can only exist because of this migration have to go
        // somewhere valid before the values disappear.
        DB::table('bicycles')
            ->whereNotIn('condition', self::LEGACY_VALUES)
            ->update(['condition' => 'needs_inspection']);

        $this->setEnum(self::LEGACY_VALUES, 'good');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function setEnum(array $values, string $default): void
    {
        $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // `condition` is a reserved word, so the column has to be quoted or
            // the server rejects the statement as a syntax error.
            DB::statement("ALTER TABLE bicycles MODIFY `condition` ENUM({$list}) NOT NULL DEFAULT '{$default}'");

            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "bicycles" DROP CONSTRAINT IF EXISTS "bicycles_condition_check"');

            $allowedValues = collect($values)
                ->map(fn (string $value): string => DB::connection()->getPdo()->quote($value))
                ->implode(', ');

            DB::statement('ALTER TABLE "bicycles" ADD CONSTRAINT "bicycles_condition_check" CHECK ("condition" IN ('.$allowedValues.'))');

            return;
        }

        Schema::table('bicycles', function (Blueprint $table) use ($values, $default) {
            $table->enum('condition', $values)->default($default)->change();
        });
    }
};
