<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$password = getenv('PERF_TEST_PASSWORD');

if ($password === false || $password === '') {
    throw new RuntimeException('PERF_TEST_PASSWORD is required.');
}

App\Models\User::updateOrCreate(
    ['email' => 'performance.login@pedalya.test'],
    [
        'name' => 'Performance Test Rider',
        'password' => Illuminate\Support\Facades\Hash::make($password),
        'role' => App\Models\User::ROLE_RIDER,
        'status' => App\Models\User::STATUS_ACTIVE,
        'verified' => false,
        'idUploaded' => false,
        'totalRentals' => 0,
        'totalSpent' => 0,
    ]
);

echo "Dedicated performance login account is ready.\n";
