<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the `status` column a value for a bicycle that is parked out of service.
 *
 * The 2026_08_23 normalization deliberately narrowed status to the operational
 * states available / rented / maintenance / removed and moved "locked" into
 * lockStatus, because a bicycle's physical lock position is not an operational
 * state. That separation is kept here.
 *
 * What it left without a spelling is a parked unit: one that is physically
 * present but not rentable and has no controllable hardware. Parking such a
 * bicycle as `available` is exactly what made it appear in the rentable fleet,
 * and `maintenance` would fabricate open maintenance work, so `out_of_service`
 * is added as its own operational state.
 *
 * `out_of_service` deliberately does not imply a lock position. A parked
 * bicycle may well be sitting unlocked, and lockStatus keeps saying so.
 */
return new class extends Migration
{
    private const VALUES = ['available', 'rented', 'maintenance', 'out_of_service', 'removed'];

    public function up(): void
    {
        $this->setEnum(self::VALUES, 'available');
    }

    public function down(): void
    {
        // Parked units must go somewhere valid before the value disappears.
        DB::table('bicycles')->where('status', 'out_of_service')->update(['status' => 'available']);

        $this->setEnum(['available', 'rented', 'maintenance', 'removed'], 'available');
    }

    /**
     * Widen the enum on every driver.
     *
     * MySQL needs raw DDL. SQLite is not a special case that can be skipped:
     * Laravel materialises an enum as a CHECK constraint, so leaving it alone
     * there makes the test database reject every write of the new value while
     * production accepts it - exactly the kind of drift that makes a suite pass
     * over a broken migration.
     *
     * @param  array<int, string>  $values
     */
    private function setEnum(array $values, string $default): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));

            DB::statement("ALTER TABLE bicycles MODIFY status ENUM({$list}) NOT NULL DEFAULT '{$default}'");

            return;
        }

        Schema::table('bicycles', function (Blueprint $table) use ($values, $default) {
            $table->enum('status', $values)->default($default)->change();
        });
    }
};