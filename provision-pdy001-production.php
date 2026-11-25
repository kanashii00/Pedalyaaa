<?php

declare(strict_types=1);

use App\Models\Bicycle;
use App\Services\RentalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! extension_loaded('pdo_mysql')) {
    throw new RuntimeException('The local PHP runtime does not have pdo_mysql enabled.');
}

$caPath = getenv('MYSQL_ATTR_SSL_CA');
if ($caPath === false || $caPath === '' || ! is_file($caPath)) {
    throw new RuntimeException('MYSQL_ATTR_SSL_CA must point to the Aiven CA PEM file.');
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.url' => null,
    'database.connections.mysql.host' => getenv('DB_HOST'),
    'database.connections.mysql.port' => getenv('DB_PORT'),
    'database.connections.mysql.database' => getenv('DB_DATABASE'),
    'database.connections.mysql.username' => getenv('DB_USERNAME'),
    'database.connections.mysql.password' => getenv('DB_PASSWORD'),
    'database.connections.mysql.options' => [
        PDO::MYSQL_ATTR_SSL_CA => $caPath,
    ],
    'cache.default' => 'array',
]);

if (getenv('DB_HOST') !== 'mysql-19b903b2-pedalya.d.aivencloud.com'
    || getenv('DB_PORT') !== '27661'
    || getenv('DB_DATABASE') !== 'defaultdb'
    || getenv('DB_USERNAME') !== 'avnadmin') {
    throw new RuntimeException('Refusing to continue: the configured Aiven target does not match the approved database.');
}

DB::purge('mysql');
DB::connection('mysql')->getPdo();

$databaseName = DB::selectOne('SELECT DATABASE() AS name')->name;
if ($databaseName !== 'defaultdb') {
    throw new RuntimeException('Refusing to continue: the selected database is not defaultdb.');
}

if (! Schema::hasTable('bicycles') || ! Schema::hasTable('migrations')) {
    throw new RuntimeException('The expected production inventory or migrations table is missing.');
}

if (DB::table('bicycles')->count() !== 0) {
    throw new RuntimeException('Refusing to provision: bicycles is no longer empty.');
}

foreach (['rentals', 'gps_logs', 'device_status', 'device_commands', 'accidents', 'geofence_breaches'] as $table) {
    if (! Schema::hasTable($table) || DB::table($table)->where('bicycleId', 1)->exists()) {
        throw new RuntimeException("Refusing to provision: unexpected or missing related table state ({$table}).");
    }
}

$keyFile = __DIR__ . '/firmware/pedalya_lcd/pedalya_lcd/secrets.h';
$keySource = file_get_contents($keyFile);
if ($keySource === false
    || ! preg_match('/^[ \t]*#define[ \t]+DEVICE_API_KEY[ \t]+"([^"\r\n]+)"/m', $keySource, $matches)
    || ! preg_match('/\A[a-f0-9]{64}\z/i', $matches[1])) {
    throw new RuntimeException('A valid 64-character per-bicycle key was not found in firmware secrets.h.');
}
$deviceKeyHash = hash('sha256', $matches[1]);
unset($matches, $keySource);

$migrationPath = 'database/migrations/2026_11_25_000001_allow_unknown_bicycle_telemetry.php';
$migrationExitCode = Artisan::call('migrate', [
    '--path' => $migrationPath,
    '--force' => true,
]);
echo Artisan::output();

if ($migrationExitCode !== 0) {
    throw new RuntimeException('The targeted nullable-telemetry migration failed.');
}

foreach (['deviceApiKey', 'deviceKeyProvisionedAt', 'iotEnabled'] as $column) {
    if (! Schema::hasColumn('bicycles', $column)) {
        throw new RuntimeException("Required bicycle column is missing: {$column}.");
    }
}

$nullableColumns = DB::select(
    "SELECT COLUMN_NAME, IS_NULLABLE
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'bicycles'
       AND COLUMN_NAME IN ('batteryLevel', 'lockStatus')"
);

if (count($nullableColumns) !== 2
    || collect($nullableColumns)->contains(fn ($column) => $column->IS_NULLABLE !== 'YES')) {
    throw new RuntimeException('The production telemetry columns are not nullable after migration.');
}

if (DB::table('bicycles')->count() !== 0
    || DB::table('bicycles')->where('id', 1)->exists()
    || DB::table('bicycles')->where('serialNumber', 'PDY-001')->exists()) {
    throw new RuntimeException('Refusing to insert: a bicycle row appeared during migration.');
}

echo "Target: {$databaseName}; bicycle ID 1 / PDY-001; telemetry will remain unknown until reported by the ESP32." . PHP_EOL;
echo "This will provision one bicycle row; it cannot be rented until real battery telemetry arrives. Type CREATE PDY-001 to continue: ";
if (trim((string) fgets(STDIN)) !== 'CREATE PDY-001') {
    throw new RuntimeException('Provisioning cancelled. The approved schema migration remains applied.');
}

DB::transaction(function () use ($deviceKeyHash): void {
    $bicycle = new Bicycle();
    $bicycle->forceFill([
        'id' => 1,
        'name' => 'Beach Cruiser 001',
        'model' => null,
        'serialNumber' => 'PDY-001',
        'status' => Bicycle::STATUS_AVAILABLE,
        'hourlyRate' => RentalService::RENTAL_RATE_PER_HOUR,
        'currentLat' => null,
        'currentLng' => null,
        'batteryLevel' => null,
        'lockStatus' => null,
        'condition' => 'good',
        'deviceApiKey' => $deviceKeyHash,
        'deviceKeyProvisionedAt' => now(),
    ]);

    if (! $bicycle->save() || ! $bicycle->provisionForService()) {
        throw new RuntimeException('Could not create and provision PDY-001.');
    }
});

$result = DB::selectOne(
    'SELECT id, serialNumber, status, iotEnabled, batteryLevel, lockStatus,
            currentLat, currentLng, deviceApiKey IS NOT NULL AS hasDeviceKey
     FROM bicycles
     WHERE id = 1'
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
