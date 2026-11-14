<?php

namespace Tests\Unit;

use App\Services\Sites\SiteHealthEvidence;
use PHPUnit\Framework\TestCase;

class SiteHealthEvidenceTest extends TestCase
{
    public function test_current_checks_are_distinct_from_missing_or_stale_evidence(): void
    {
        $s = new SiteHealthEvidence;
        $this->assertSame('UNKNOWN', $s->summarize('ACTIVE', [], 10000)['state']);
        $this->assertSame('CURRENT_CLEAR', $s->summarize('ACTIVE', [['status' => 'HEALTHY', 'observed_at' => 9999]], 10000)['state']);
        $result = $s->summarize('ACTIVE', [['status' => 'BROKEN', 'observed_at' => 9999]], 10000);
        $this->assertSame('CURRENT_FINDINGS', $result['state']);
        $this->assertSame(1, $result['findings']);
        $this->assertSame('STALE', $s->summarize('ACTIVE', [['status' => 'HEALTHY', 'observed_at' => 6399]], 10000)['state']);
    }

    public function test_one_recent_record_cannot_hide_old_unknown_or_future_records(): void
    {
        $s = new SiteHealthEvidence;
        foreach ([null, 10001] as $time) {
            $this->assertSame('UNKNOWN', $s->summarize('ACTIVE', [
                ['status' => 'HEALTHY', 'observed_at' => 9999], ['status' => 'HEALTHY', 'observed_at' => $time],
            ], 10000)['state']);
        }
        $this->assertSame('STALE', $s->summarize('ACTIVE', [
            ['status' => 'HEALTHY', 'observed_at' => 9999], ['status' => 'BROKEN', 'observed_at' => 100],
        ], 10000)['state']);
        $this->assertSame('UNKNOWN', $s->summarize('ACTIVE', [['status' => 'NEW_STATUS', 'observed_at' => 9999]], 10000)['state']);
    }

    public function test_inactive_sites_do_not_receive_a_broken_serving_label(): void
    {
        foreach (['DRAFT', 'SUSPENDED', 'ARCHIVED', 'APPROVED'] as $status) {
            $this->assertSame('NOT_MONITORED', (new SiteHealthEvidence)->summarize($status, [['status' => 'BROKEN', 'observed_at' => 9999]], 10000)['state']);
        }
    }
}
