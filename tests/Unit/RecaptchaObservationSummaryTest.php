<?php

namespace Tests\Unit;

use App\Services\Sites\RecaptchaObservationSummary;
use PHPUnit\Framework\TestCase;

class RecaptchaObservationSummaryTest extends TestCase
{
    public function test_saved_enabled_is_not_mistaken_for_a_published_or_executed_configuration(): void
    {
        $s = new RecaptchaObservationSummary;
        $this->assertSame('NO_PUBLICATION_EVIDENCE', $s->describe(true, 'key', false, ['enabled' => true, 'siteKey' => 'key'])['state']);
        $this->assertSame('UPDATE_PENDING', $s->describe(true, 'new-key', true, ['enabled' => true, 'siteKey' => 'old-key'])['state']);
        $result = $s->describe(true, 'new-key', true, ['enabled' => true, 'siteKey' => 'new-key']);
        $this->assertSame('CONFIGURATION_PUBLISHED', $result['state']);
        $this->assertTrue($result['published_key_matches']);
        $this->assertStringNotContainsString('new-key', json_encode($result));
        $this->assertArrayNotHasKey('score', $result);
    }

    public function test_disabling_does_not_hide_a_still_enabled_published_version(): void
    {
        $s = new RecaptchaObservationSummary;
        $this->assertSame('DISABLE_PENDING', $s->describe(false, null, true, ['enabled' => true, 'siteKey' => 'key'])['state']);
        $this->assertSame('DISABLED', $s->describe(false, null, true, ['enabled' => false, 'siteKey' => null])['state']);
        $this->assertSame('NO_PUBLICATION_EVIDENCE', $s->describe(true, 'key', true, null)['state']);
    }
}
