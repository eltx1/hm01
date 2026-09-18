<?php

namespace Tests\Unit;

use App\Services\Demand\GoogleGptManualTagParser;
use RuntimeException;
use Tests\TestCase;

final class GoogleGptManualTagParserFluidTest extends TestCase
{
    public function test_parser_accepts_mixed_fixed_and_official_fluid_sizes(): void
    {
        $parsed = (new GoogleGptManualTagParser())->parse($this->tag('[[300, 250], "fluid"]'));

        $this->assertNotNull($parsed);
        $this->assertSame([[300, 250], 'fluid'], $parsed['sizes']);
    }

    public function test_parser_accepts_single_fluid_named_size(): void
    {
        $parsed = (new GoogleGptManualTagParser())->parse($this->tag("['fluid']"));

        $this->assertNotNull($parsed);
        $this->assertSame(['fluid'], $parsed['sizes']);
    }

    public function test_parser_rejects_unsupported_named_size_instead_of_guessing_native(): void
    {
        $this->expectException(RuntimeException::class);

        (new GoogleGptManualTagParser())->parse($this->tag('["native"]'));
    }

    private function tag(string $sizes): string
    {
        return <<<HTML
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="gpt-in-article"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/1234567/lordai_in_article', {$sizes}, 'gpt-in-article').addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('gpt-in-article');
});
</script>
HTML;
    }
}
