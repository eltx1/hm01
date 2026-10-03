<?php

namespace App\Console\Commands;

use App\Services\Reporting\GamHistoricalOperation;
use Illuminate\Console\Command;

final class RunGamHistoricalOperation extends Command
{
    protected $signature = 'reporting:gam-historical-operation
        {mode=discover : discover, prepare, poll, status, apply, or execute-once}
        {--operation= : Opaque 64-character lowercase hexadecimal operation token}
        {--actor-fingerprint= : SHA-256 of the verified existing normalized application login}
        {--actor-selector= : Operation-scoped actor selector for the immutable one-time contract}
        {--activation-sha256= : Exact trusted activation manifest checksum}
        {--digest= : Exact reviewed operation digest, required for apply}
        {--limit=1 : Sequential batch limit from 1 through 6}
        {--result-file= : New result.json in an existing private directory}';

    protected $description = 'Run a private, bounded historical GAM correction operation with sanitized output.';

    public function handle(GamHistoricalOperation $operations): int
    {
        $operation = (string) $this->option('operation');
        $limit = (string) $this->option('limit');
        $output = $this->option('result-file');
        // Validate the output destination before any Google or financial work.
        if ($output && (! is_string($output) || ! str_starts_with($output, '/') || basename($output) !== 'result.json'
            || is_file($output) || is_link($output) || realpath(dirname($output)) !== dirname($output)
            || (fileperms(dirname($output)) & 0077) !== 0)) return self::FAILURE;
        if ($output) {
            foreach ([public_path(), storage_path('app/public')] as $publicRoot) {
                $publicRoot = realpath($publicRoot) ?: $publicRoot;
                if (dirname($output) === $publicRoot || str_starts_with($output, $publicRoot.'/')) return self::FAILURE;
            }
        }
        $level = ob_get_level();
        ob_start();
        try {
            $result = $this->argument('mode') === 'execute-once'
                ? $this->executeOnce($operations, $operation, $limit)
                : $operations->run((string) $this->argument('mode'), $operation, (string) $this->option('actor-fingerprint'),
                    preg_match('/^[1-6]$/D', $limit) ? (int) $limit : 0, (string) $this->option('digest'));
        } finally {
            $diagnostic = '';
            while (ob_get_level() > $level) $diagnostic = ob_get_clean().$diagnostic;
            // Third-party diagnostic output belongs only in the existing private
            // operation directory, never alongside the sanitized console result.
            $directory = storage_path('app/private/gam-historical-operations');
            if ($diagnostic !== '' && preg_match('/^[a-f0-9]{64}$/D', $operation) && is_dir($directory)
                && ! is_link($directory) && (fileperms($directory) & 0077) === 0) {
                $log = $directory.'/'.$operation.'.console.log';
                $old = umask(0077);
                try { if (! is_link($log)) @file_put_contents($log, $diagnostic, FILE_APPEND | LOCK_EX); }
                finally { umask($old); }
            }
        }
        $result['reasons'] = (object) $result['reasons'];
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        if ($output) {
            $old = umask(0077);
            try {
                $file = fopen($output, 'x');
                if (! $file) return self::FAILURE;
                try { if (fwrite($file, $json) !== strlen($json)) return self::FAILURE; }
                finally { fclose($file); }
            } finally { umask($old); }
        } else $this->output->write($json);
        return $result['outcome'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }

    private function executeOnce(GamHistoricalOperation $operations, string $operation, string $limit): array
    {
        $failure = static fn (string $reason): array => [
            'schema_version' => 1, 'outcome' => 'FAILED', 'reason' => $reason,
            'operation' => preg_match('/^[a-f0-9]{64}$/D', $operation) ? $operation : str_repeat('0', 64),
            'digest' => null,
            'counts' => array_fill_keys(['sources', 'daily_facts', 'hourly_facts', 'forward_facts', 'windows', 'eligible_windows',
                'blocked_facts', 'corrected_facts', 'pending', 'ready', 'applied', 'blocked'], 0),
            'reasons' => [],
        ];
        $result = null;
        try {
            $path = base_path('ops/audit/historical-gam-correction-once.json');
            $checksum = (string) $this->option('activation-sha256');
            if (! is_file($path) || is_link($path) || filesize($path) > 4096 || ! preg_match('/^[a-f0-9]{64}$/D', $checksum)
                || $checksum !== '9e8a62704cc8b322f1ef0ecc8b6baacb2220ea41740f83a685c5c11f58808f26'
                || ! hash_equals($checksum, hash_file('sha256', $path))) return $failure('CONFIGURATION_INVALID');
            $contract = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $expected = [
                'schema_version' => 1,
                'operation' => '43c093f93251f353c7ba306e44d4c1e36a03e835bbd0d6ebe2463ae3fbe8cf45',
                'actor_selector' => '4cf689eb22a69a19a1838d98a97efb33c47c8a88ddca10d1faba79b847582f07',
                'through' => '2026-10-02',
                'activation_base_sha' => '37569a88ef6180266de313d00487445e6d475079',
                'budget_seconds' => 900,
                'batch_limit' => 1,
            ];
            if ($contract !== $expected || $operation !== $contract['operation'] || $limit !== '1'
                || $this->option('actor-selector') !== $contract['actor_selector']
                || (string) $this->option('actor-fingerprint') !== '' || (string) $this->option('digest') !== '') {
                return $failure('CONFIGURATION_INVALID');
            }
            $serviceContract = array_intersect_key($contract, array_flip(['schema_version', 'operation', 'actor_selector', 'through']));
            // A monotonic budget is independent of wall-clock adjustments. Each
            // step checkpoints private state; no new identity is made on retry.
            $deadline = hrtime(true) + $contract['budget_seconds'] * 1_000_000_000;
            while (hrtime(true) < $deadline) {
                $result = $operations->advanceOneTime($serviceContract);
                if ($result['reason'] !== 'NONE' || $result['outcome'] === 'FAILED') {
                    $result['outcome'] = 'FAILED';

                    return $result;
                }
                if ($result['counts']['pending'] === 0 && $result['counts']['ready'] === 0) {
                    if ($result['outcome'] === 'BLOCKED' || $result['counts']['blocked_facts'] > 0 || $result['counts']['blocked'] > 0) {
                        $result['outcome'] = 'FAILED';
                        $result['reason'] = 'INCOMPLETE';
                    }

                    return $result;
                }
                if (hrtime(true) + 5_000_000_000 >= $deadline) break;
                sleep(5);
            }
            $result ??= $failure('INCOMPLETE');
            $result['outcome'] = 'FAILED';
            $result['reason'] = 'INCOMPLETE';

            return $result;
        } catch (\Throwable) {
            // Earlier steps may have committed receipts. Keep the last known
            // counts rather than reporting a misleading zero-progress failure.
            $result ??= $failure('OPERATION_FAILED');
            $result['outcome'] = 'FAILED';
            $result['reason'] = 'OPERATION_FAILED';

            return $result;
        }
    }
}
