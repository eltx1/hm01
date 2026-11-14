<?php

namespace Tests\Unit;

use App\Services\Sites\SitePublicationSummary;
use PHPUnit\Framework\TestCase;

class SitePublicationSummaryTest extends TestCase
{
    public function test_pending_new_version_does_not_hide_confirmed_previous_version(): void
    {
        $summary = (new SitePublicationSummary)->describe(8, 'PENDING_DELIVERY', 'PENDING', 7, '2026-11-14T12:00:00+00:00');
        $this->assertSame('PENDING', $summary['state']);
        $this->assertSame(7, $summary['published_version']);
        $this->assertSame(8, $summary['latest_version']);
    }

    public function test_only_matching_pointer_and_delivery_evidence_confirm_publication(): void
    {
        $service = new SitePublicationSummary;
        $this->assertSame('PUBLISHED', $service->describe(8, 'DEPLOYED', 'DEPLOYED', 8)['state']);
        $this->assertSame('PENDING', $service->describe(8, 'DEPLOYED', 'DEPLOYED', 7)['state']);
        $this->assertSame('PENDING', $service->describe(8, 'PENDING_DELIVERY', 'DEPLOYED', 8)['state']);
        $this->assertSame('PENDING', $service->describe(8, 'DEPLOYED', null, 8)['state']);
        $this->assertSame('PUBLISHED', $service->describe(8, 'PUBLISHED', null, 8)['state']);
    }

    public function test_failure_and_retry_are_visible_even_when_an_older_version_is_published(): void
    {
        $service = new SitePublicationSummary;
        foreach (['FAILED' => 'FAILED', 'RETRY_SCHEDULED' => 'RETRY_SCHEDULED', 'BATCHING' => 'PUBLISHING', 'UPLOADING' => 'PUBLISHING', 'SUPERSEDED' => 'SUPERSEDED'] as $status => $expected) {
            $this->assertSame($expected, $service->describe(8, 'PENDING_DELIVERY', $status, 7)['state']);
        }
        $this->assertSame('FAILED', $service->describe(8, 'DELIVERY_FAILED', null, 7)['state']);
        $this->assertSame('ROLLED_BACK', $service->describe(8, 'ROLLED_BACK', 'DEPLOYED', 7)['state']);
        $this->assertSame('NOT_QUEUED', $service->describe(null, null, null, null)['state']);
    }
}
