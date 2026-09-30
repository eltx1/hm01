<?php

namespace Tests\Unit;

use App\Services\Demand\GoogleVideoAdTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GoogleVideoAdTagTest extends TestCase
{
    public function test_path_template_preserves_case_nested_units_and_mcm_network_pair(): void
    {
        $url = (new GoogleVideoAdTag())->build(' /123,456/site.net/Article_Video-1 ', [640, 480]);
        $this->assertSame('pubads.g.doubleclick.net', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/gampad/ads', parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('/123,456/site.net/Article_Video-1', $query['iu']);
        $this->assertSame('640x480', $query['sz']);
        $this->assertSame('linear', $query['vad_type']);
        $this->assertSame('video', $query['ad_type']);
        $this->assertArrayNotHasKey('nofb', $query);
        foreach (['url', 'description_url', 'gdpr', 'gdpr_consent', 'npa', 'tfcd', 'vpa', 'vpmute', 'correlator'] as $dynamic) $this->assertArrayNotHasKey($dynamic, $query);
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_malformed_paths_and_invalid_master_dimensions(string $input, array $size): void
    {
        $this->expectException(RuntimeException::class);
        (new GoogleVideoAdTag())->build($input, $size);
    }

    public static function invalidInputs(): array
    {
        return [
            ['/network/video', [400, 225]], ['/123/video?nofb=1', [400, 225]],
            ['/123,456,789/video', [400, 225]], ['/123//video', [400, 225]],
            ['/123/../video', [400, 225]], ['/123/./video', [400, 225]],
            ['https://example.com/video', [400, 225]], ['//example.com/video', [400, 225]],
            ['/123/video', [0, 225]], ['/123/video', [400, -1]],
        ];
    }
}
