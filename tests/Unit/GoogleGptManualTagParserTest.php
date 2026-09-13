<?php

namespace Tests\Unit;

use App\Services\Demand\GoogleGptManualTagParser;
use RuntimeException;
use Tests\TestCase;

final class GoogleGptManualTagParserTest extends TestCase
{
    public function test_plain_static_gpt_tag_is_supported(): void
    {
        $parsed = (new GoogleGptManualTagParser())->parse($this->plainTag());

        $this->assertIsArray($parsed);
        $this->assertSame('/1234567/header', $parsed['adUnitPath']);
        $this->assertSame('div-gpt-header', $parsed['containerId']);
        $this->assertSame([[300, 250]], $parsed['sizes']);
    }

    public function test_gpt_slot_modifier_is_rejected_instead_of_silently_dropped(): void
    {
        $tag = <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="div-gpt-header"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/1234567/header', [300, 250], 'div-gpt-header')
    .setTargeting('position', 'top')
    .addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('div-gpt-header');
});
</script>
HTML;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support GPT modifiers or operations [setTargeting]');

        (new GoogleGptManualTagParser())->parse($tag);
    }

    public function test_gpt_size_mapping_operation_is_rejected_instead_of_silently_dropped(): void
    {
        $tag = <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="div-gpt-header"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/1234567/header', [300, 250], 'div-gpt-header')
    .defineSizeMapping([])
    .addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('div-gpt-header');
});
</script>
HTML;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support GPT modifiers or operations [defineSizeMapping]');

        (new GoogleGptManualTagParser())->parse($tag);
    }

    private function plainTag(): string
    {
        return <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="div-gpt-header"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/1234567/header', [300, 250], 'div-gpt-header').addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('div-gpt-header');
});
</script>
HTML;
    }
}
