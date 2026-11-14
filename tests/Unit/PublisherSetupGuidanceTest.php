<?php

namespace Tests\Unit;

use App\Services\Sites\PublisherSetupGuidance;
use PHPUnit\Framework\TestCase;

class PublisherSetupGuidanceTest extends TestCase
{
    public function test_guidance_covers_the_existing_lifecycle_without_creating_provider_requirements(): void
    {
        $s = new PublisherSetupGuidance;
        foreach (['DRAFT', 'PENDING_VERIFICATION', 'PENDING_REVIEW', 'APPROVED', 'ACTIVE', 'REJECTED', 'SUSPENDED', 'ARCHIVED'] as $status) {
            $step = $s->describe($status);
            $this->assertNotEmpty($step['title']);
            $this->assertContains($step['owner'], ['Publisher', 'Horus Media']);
            $this->assertStringNotContainsString('GAM', json_encode($step));
            $this->assertStringNotContainsString('MCM', json_encode($step));
        }
        $this->assertSame('Horus Media', $s->describe('PENDING_REVIEW')['owner']);
        $this->assertSame('#ads-txt-setup', $s->describe('DRAFT')['target']);
        $this->assertSame('#site-installation', $s->describe('ACTIVE')['target']);
        $this->assertNull($s->describe('UNKNOWN')['target']);
    }
}
