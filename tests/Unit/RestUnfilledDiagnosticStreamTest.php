<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RestUnfilledDiagnosticStreamTest extends TestCase
{
    private function payload(): string
    {
        $root = dirname(__DIR__, 2);
        return file_get_contents($root.'/ops/audit/gam-rest-unfilled-probe.php')
            .implode("\n", array_slice(explode("\n", file_get_contents($root.'/ops/audit/gam-rest-unfilled-diagnostic.php')), 3));
    }

    public function test_exact_rest_only_stream_has_valid_php_syntax(): void
    {
        $process = new Process([PHP_BINARY, '-l']);
        $process->setInput($this->payload())->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/diagnose-unfilled-rest.yml');
        $this->assertStringContainsString('tail -n +4 ops/audit/gam-rest-unfilled-diagnostic.php', $workflow);
    }

    public function test_wrong_or_missing_release_refuses_before_application_bootstrap(): void
    {
        $directory = sys_get_temp_dir().'/rest-unfilled-guard-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            foreach ([null, str_repeat('a', 40)] as $release) {
                $process = new Process([PHP_BINARY], $directory, ['HORUS_EXPECTED_RELEASE' => $release ?? false]);
                $process->setInput($this->payload())->run();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame(['schema_version' => 1, 'diagnostic' => 'REST_UNFILLED_ONLY', 'reason' => 'RELEASE_MISMATCH'], json_decode($process->getOutput(), true));
                $this->assertSame('', $process->getErrorOutput());
            }
            file_put_contents($directory.'/.horus-release', 'release_id='.str_repeat('b', 40)."\n");
            $process = new Process([PHP_BINARY], $directory, ['HORUS_EXPECTED_RELEASE' => str_repeat('a', 40)]);
            $process->setInput($this->payload())->run();
            $this->assertSame('RELEASE_MISMATCH', json_decode($process->getOutput(), true)['reason']);
        } finally {
            if (is_file($directory.'/.horus-release')) unlink($directory.'/.horus-release');
            rmdir($directory);
        }
    }
}
