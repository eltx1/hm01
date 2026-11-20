<?php

namespace Tests\Unit;

use App\Enums\SiteStatus;
use App\Support\DisplayLabel;
use Tests\TestCase;

class DisplayLabelTest extends TestCase
{
    public function test_single_upper_case_words_are_not_split_into_letters(): void
    {
        $this->assertSame('Active', DisplayLabel::from('ACTIVE'));
        $this->assertSame('Publisher', DisplayLabel::from('PUBLISHER'));
        $this->assertSame('Active', (string) str('ACTIVE')->replace('_', ' ')->label());
        $this->assertSame('Active', DisplayLabel::from(SiteStatus::Active));
    }

    public function test_multi_word_codes_keep_title_case_and_acronyms(): void
    {
        $this->assertSame('Pending Review', DisplayLabel::from('PENDING_REVIEW'));
        $this->assertSame('Horus Media', DisplayLabel::from('HORUS_MEDIA'));
        $this->assertSame('Direct GAM', DisplayLabel::from('DIRECT_GAM'));
        $this->assertSame('AI Disabled', DisplayLabel::from('AI DISABLED'));
        $this->assertSame('eCPM', DisplayLabel::from('ECPM'));
        $this->assertSame('Source Gross', DisplayLabel::from('sourceGross'));
        $this->assertSame('Organic', (string) str('organic')->label());
        $this->assertSame('', DisplayLabel::from(null));
    }

    public function test_status_badges_render_readable_words(): void
    {
        $html = $this->blade('<x-status-badge status="ACTIVE" /><x-status-badge status="DRAFT" />');

        $html->assertSee('>Active<', false)->assertSee('>Draft<', false)->assertDontSee('A C T I V E');
    }
}
