<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give every bicycle its own device credential for the on-board ESP32 display.
 *
 * The shared services.device_api_key is not enough for the rental countdown
 * endpoint: it is identical on every unit, so one compromised bicycle could
 * read another bicycle's renter, deadline and penalty figures. Each key is
 * therefore bound to a single bicycle and stored as a SHA-256 digest, the same
 * scheme Laravel Sanctum uses for personal access tokens. A 256-bit random
 * token is not brute-forceable from its digest, and the digest stays indexable
 * so authentication is a single lookup instead of a scan with a hash per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bicycles', function (Blueprint $table) {
            $table->string('deviceApiKey', 64)->nullable()->unique()->after('qrCode');
            $table->timestamp('deviceKeyProvisionedAt')->nullable()->after('deviceApiKey');
        });
    }

    public function down(): void
    {
        Schema::table('bicycles', function (Blueprint $table) {
            $table->dropUnique(['deviceApiKey']);
            $table->dropColumn(['deviceApiKey', 'deviceKeyProvisionedAt']);
        });
    }
};
