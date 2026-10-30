<?php

if ($argc !== 3 || !in_array($argv[1], ['1', '2'], true)) {
    fwrite(STDERR, "Usage: php performance-testing/run-iot-k6.php <1|2> <evidence-prefix>\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$vus = $argv[1];
$prefix = $argv[2];
$root = realpath(__DIR__ . '/..');
$summary = $root . '/performance-testing/evidence/' . $prefix . '-summary.json';
$console = $root . '/performance-testing/evidence/' . $prefix . '-console.txt';
$script = $root . '/performance-testing/k6-iot-heartbeat.js';
$k6 = 'C:\\Program Files\\k6\\k6.exe';

putenv('DEVICE_API_KEY=' . config('services.device_api_key'));
putenv('K6_VUS=' . $vus);
putenv('K6_DURATION=3m');

$command = sprintf('"%s" run --quiet --summary-export "%s" "%s"', $k6, $summary, $script);
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $console, 'w'],
    2 => ['file', $console, 'a'],
], $pipes, $root);

if (!is_resource($process)) {
    throw new RuntimeException('Could not start k6.');
}

fclose($pipes[0]);
$status = proc_close($process);
exit($status);
