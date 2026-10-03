<?php

namespace Tests\Unit;

use HorusUnfilledEvidence as Evidence;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class UnfilledEvidenceClassificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('HORUS_UNFILLED_EVIDENCE_LIBRARY_ONLY')) define('HORUS_UNFILLED_EVIDENCE_LIBRARY_ONLY', true);
        require_once dirname(__DIR__, 2).'/ops/audit/classify-unfilled-evidence.php';
    }

    private function records(string $reason = 'SERVICE_DISABLED', string $label = '(Not applicable)'): array
    {
        $soap = array_fill(0, 3, [['csv' => "Dimension.SITE_NAME,Column.TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS\n{$label},12345\n"]]);
        $rest = array_fill(0, 3, [
            ['request' => ['method' => 'GET', 'path' => 'networks/123456']],
            ['response' => ['error' => ['status' => 'PERMISSION_DENIED', 'code' => 403, 'message' => 'PRIVATE_HOST.EXAMPLE private project',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'domain' => 'googleapis.com', 'reason' => $reason,
                    'metadata' => ['consumer' => 'projects/123456']]]]]],
        ]);
        $soap[0][] = ['summary' => ['schema_version' => 1, 'metric' => 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS',
            'scope' => 'AD_UNIT_AND_EXACT_SITE', 'period' => 'LAST_SEVEN_COMPLETE_DAYS', 'bindings' => 3,
            'probes' => array_fill(0, 3, ['query_status' => 'COMPLETED', 'valid_csv' => true, 'exact_site_observed' => false, 'nonmatching_site_observed' => true]),
            'rest' => ['probes' => array_fill(0, 3, ['query_status' => 'ACCESS_BLOCKED', 'reason' => 'REST_ACCESS_BLOCKED', 'definition_status' => 'NOT_SELECTED'])]]];
        return [$soap, $rest];
    }

    public function test_streamed_classifier_is_valid_standalone_php(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, '-l']);
        $process->setInput(file_get_contents($root.'/ops/audit/classify-unfilled-evidence.php'))->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('cat ops/audit/classify-unfilled-evidence.php |', file_get_contents($root.'/.github/workflows/deploy-production.yml'));
    }

    public function test_classifies_known_causes_without_disclosing_message_identifiers_or_counters(): void
    {
        foreach (Evidence::REASONS as $reason) {
            $result = Evidence::classify(...$this->records($reason));
            $this->assertSame([$reason], $result['bindings'][0]['rest_reasons']);
            $this->assertSame('GET_NETWORK', $result['bindings'][0]['rest_stage']);
            $this->assertSame(['NOT_APPLICABLE'], $result['bindings'][0]['soap_site_categories']);
            $this->assertFalse($result['new_google_requests']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($result));
            $this->assertStringNotContainsString('12345', json_encode($result));
        }
    }

    public function test_unknown_or_untrusted_error_reason_is_not_guessed_from_message(): void
    {
        [$soap, $rest] = $this->records('SECRET_ERROR');
        $rest[0][1]['response']['error']['message'] = 'SERVICE_DISABLED';
        $this->assertSame(['UNCLASSIFIED'], Evidence::classify($soap, $rest)['bindings'][0]['rest_reasons']);
        $rest[0][1]['response']['error']['details'][0]['reason'] = 'SERVICE_DISABLED';
        $rest[0][1]['response']['error']['details'][0]['domain'] = 'untrusted.example';
        $this->assertSame(['UNCLASSIFIED'], Evidence::classify($soap, $rest)['bindings'][0]['rest_reasons']);
    }

    public function test_missing_error_body_remains_explicitly_unclassified(): void
    {
        [$soap, $rest] = $this->records();
        $rest[0] = [$rest[0][0], ['reason' => 'REST_ACCESS_BLOCKED']];
        $row = Evidence::classify($soap, $rest)['bindings'][0];
        $this->assertFalse($row['structured_error_present']);
        $this->assertSame('UNKNOWN', $row['rest_status']);
        $this->assertSame(['UNCLASSIFIED'], $row['rest_reasons']);
    }

    public function test_other_host_and_placeholder_categories_do_not_expose_labels(): void
    {
        foreach (['private.example' => 'OTHER_HOSTNAME', '(unknown)' => 'UNKNOWN', '' => 'EMPTY', 'private label' => 'OTHER_LABEL'] as $label => $category) {
            $this->assertSame([$category], Evidence::classify(...$this->records(label: $label))['bindings'][0]['soap_site_categories']);
        }
    }

    public function test_different_summary_and_any_write_request_are_rejected(): void
    {
        [$soap, $rest] = $this->records();
        $rest[0][0]['request']['method'] = 'POST';
        try { Evidence::classify($soap, $rest); $this->fail('Write request accepted'); }
        catch (RuntimeException $e) { $this->assertSame('EVIDENCE_INVALID', $e->getMessage()); }
        [$soap, $rest] = $this->records(); $soap[0][1]['summary']['bindings'] = 2;
        $this->expectExceptionMessage('EVIDENCE_INVALID'); Evidence::classify($soap, $rest);
    }

    public function test_filesystem_requires_exact_release_timestamp_single_directory_and_regular_files(): void
    {
        $root = sys_get_temp_dir().'/unfilled-classify-'.bin2hex(random_bytes(8));
        $base = $root.'/storage/app/private/gam-unfilled-probe';
        $directory = $base.'/'.str_repeat('a', 24);
        mkdir($directory, 0700, true);
        try {
            file_put_contents($root.'/.horus-release', 'release_id='.Evidence::RELEASE."\n");
            [$soap, $rest] = $this->records();
            foreach (['soap' => $soap, 'rest' => $rest] as $type => $records) {
                foreach ($records as $index => $lines) {
                    $file = $directory.'/'.$type.'-'.$index.'.jsonl';
                    file_put_contents($file, implode("\n", array_map(static fn ($row) => json_encode($row), $lines))."\n");
                    touch($file, Evidence::FROM + 5);
                }
            }
            touch($directory, Evidence::FROM + 5); clearstatcache();
            $this->assertCount(3, Evidence::inspect($root)['bindings']);
            touch($directory.'/rest-0.jsonl', Evidence::TO + 1); clearstatcache();
            try { Evidence::inspect($root); $this->fail('Stale file accepted'); }
            catch (RuntimeException $e) { $this->assertSame('EVIDENCE_INVALID', $e->getMessage()); }
            touch($directory.'/rest-0.jsonl', Evidence::FROM + 5);
            $second = $base.'/'.str_repeat('b', 24); mkdir($second); touch($second, Evidence::FROM + 5); clearstatcache();
            try { Evidence::inspect($root); $this->fail('Ambiguous evidence accepted'); }
            catch (RuntimeException $e) { $this->assertSame('EVIDENCE_AMBIGUOUS', $e->getMessage()); }
            rmdir($second); unlink($directory.'/rest-0.jsonl'); symlink($directory.'/rest-1.jsonl', $directory.'/rest-0.jsonl');
            touch($directory, Evidence::FROM + 5); clearstatcache();
            try { Evidence::inspect($root); $this->fail('Symlink accepted'); }
            catch (RuntimeException $e) { $this->assertSame('EVIDENCE_INVALID', $e->getMessage()); }
            file_put_contents($root.'/.horus-release', "release_id=wrong\n");
            $this->expectExceptionMessage('SOURCE_RELEASE_MISMATCH'); Evidence::inspect($root);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
            rmdir($directory); rmdir($base); rmdir(dirname($base)); rmdir($root.'/storage/app'); rmdir($root.'/storage');
            unlink($root.'/.horus-release'); rmdir($root);
        }
    }
}
