<?php

namespace Tests\Unit;

use App\Services\Demand\GoogleRewardedAdUnitPath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GoogleRewardedAdUnitPathTest extends TestCase
{
    public function test_accepts_plain_and_mcm_paths_but_leaves_urls_and_code_alone(): void
    {
        $parser = new GoogleRewardedAdUnitPath();
        $this->assertSame('/123/unit', $parser->parse(' /123/unit '));
        $this->assertSame('/123,456/site/reward', $parser->parse('/123,456/site/reward'));
        $this->assertNull($parser->parse('https://example.com/vast'));
        $this->assertNull($parser->parse('<script src="https://example.com/tag.js"></script>'));
    }

    public function test_rejects_code_in_a_unit_path(): void
    {
        $this->expectException(RuntimeException::class);
        (new GoogleRewardedAdUnitPath())->parse('/123/unit?callback=alert(1)');
    }
}
