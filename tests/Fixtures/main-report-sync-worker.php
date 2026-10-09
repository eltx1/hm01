<?php

// Synthetic CI worker. Never accepts or discovers production credentials.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || ! getenv('HORUS_LOCK_TEST_CONNECTION')) exit(3);
config(['database.default' => 'lock_process_test', 'database.connections.lock_process_test' => json_decode(getenv('HORUS_LOCK_TEST_CONNECTION'), true, flags: JSON_THROW_ON_ERROR)]);
$lock = app(App\Services\Reporting\MainReportSyncLock::class);
$emit = static function (array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); };
try {
    if (! $lock->acquire()) { $emit(['state' => 'busy']); exit(0); }
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    $emit(['state' => 'acquired', 'session' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
    while (($line = fgets(STDIN)) !== false) {
        $command = trim($line);
        if ($command === 'release') { $lock->release(); $emit(['state' => 'released']); exit(0); }
        if ($command === 'expire') { $pdo->exec('SET SESSION wait_timeout = 1'); $emit(['state' => 'expiring']); continue; }
        if ($command === 'probe') { $lock->assertOwned(); $emit(['state' => 'owned']); continue; }
        if ($command === 'late-write') {
            $table = $argv[1] ?? '';
            if (! preg_match('/^main_sync_test_[a-f0-9]{12}$/D', $table)) exit(4);
            // A provider response resumes after the parent has killed/expired this session.
            $lock->assertOwned();
            Illuminate\Support\Facades\DB::table($table)->insert(['marker' => 'late-response']);
            $emit(['state' => 'wrote']);
        }
    }
    $lock->release();
} catch (App\Services\Reporting\MainReportSyncLockException) {
    try { $lock->release(); } catch (App\Services\Reporting\MainReportSyncLockException) {}
    $emit(['state' => 'lost']);
    exit(2);
}
