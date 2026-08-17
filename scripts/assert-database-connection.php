<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expected = $argv[1] ?? null;
if (! in_array($expected, ['mysql', 'sqlite'], true)) {
    fwrite(STDERR, "Usage: php scripts/assert-database-connection.php <mysql|sqlite>\n");
    exit(2);
}

$configured = (string) config('database.default');
$driver = (string) DB::connection()->getDriverName();

if ($configured !== $expected || $driver !== $expected) {
    fwrite(STDERR, sprintf(
        "Database contract failed: expected %s, configured %s, active driver %s.\n",
        $expected,
        $configured,
        $driver,
    ));
    exit(1);
}

fwrite(STDOUT, sprintf("Database contract verified: %s.\n", $expected));
