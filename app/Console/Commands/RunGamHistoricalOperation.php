<?php

namespace App\Console\Commands;

use App\Services\Reporting\GamHistoricalOperation;
use Illuminate\Console\Command;

final class RunGamHistoricalOperation extends Command
{
    protected $signature = 'reporting:gam-historical-operation
        {mode=discover : discover, prepare, poll, status, or apply}
        {--operation= : Opaque 64-character lowercase hexadecimal operation token}
        {--actor-fingerprint= : SHA-256 of the verified existing normalized application login}
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
            $result = $operations->run((string) $this->argument('mode'), $operation, (string) $this->option('actor-fingerprint'),
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
}
