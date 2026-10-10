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
        $this->assertSame('640x480|1x1|288x162|300x250|335x200|400x225|400x300|419x236|640x360|1920x1080|320x480|444x250|480x320|480x360|600x252|600x338|720x405|1024x768|1280x720', $query['sz']);
        $this->assertSame('xml_vast4', $query['output']);
        $this->assertSame('linear', $query['vad_type']);
        $this->assertSame('video', $query['ad_type']);
        $this->assertArrayNotHasKey('nofb', $query);
        foreach (['url', 'description_url', 'gdpr', 'gdpr_consent', 'npa', 'tfcd', 'vpa', 'vpmute', 'correlator'] as $dynamic) $this->assertArrayNotHasKey($dynamic, $query);
    }

    public function test_mixed_template_omits_only_the_linear_restriction(): void
    {
        $tags = new GoogleVideoAdTag();
        parse_str(parse_url($tags->build('/123/video', [400, 225]), PHP_URL_QUERY), $linear);
        parse_str(parse_url($tags->build('/123/video', [400, 225], 'mixed'), PHP_URL_QUERY), $mixed);
        unset($linear['vad_type']);
        $this->assertSame($linear, $mixed);
        $this->assertSame('video', $mixed['ad_type']);
    }

    public function test_recognized_generated_templates_regenerate_with_current_master_and_format(): void
    {
        $tags = new GoogleVideoAdTag();
        foreach (['video_only', 'mixed'] as $previous) {
            $template = $tags->build('/123,456/site/Video', [400, 225], $previous);
            foreach (['video_only', 'mixed'] as $next) {
                $updated = $tags->build('/123,456/site/Video', [640, 480], $next);
                $this->assertSame($updated, $tags->regenerate($template, '/123,456/site/Video', [640, 480], $next));
                $this->assertSame($updated, $tags->regenerate($updated, '/123,456/site/Video', [640, 480], $next));
            }
        }
    }

    public function test_regeneration_rejects_edited_urls_and_mismatched_provenance(): void
    {
        $tags = new GoogleVideoAdTag();
        $template = $tags->build('/123/video', [400, 225]);
        foreach ([
            $template.'&ad_rule=1', $template.'&gdpr=1&gdpr_consent=reviewed',
            $template.'&npa=1', $template.'&custom=preserved',
            $template.'%7C800x600', $template.'%7C400x225',
            str_replace('vad_type=linear', 'vad_type=nonlinear', $template),
            str_replace('vad_type=linear', 'vad_type=linear_nonlinear', $template),
            str_replace('output=xml_vast4', 'output=vmap', $template),
            str_replace('pubads.g.doubleclick.net', 'ads.example.com', $template),
        ] as $edited) {
            $this->assertNull($tags->regenerate($edited, '/123/video', [640, 480], 'mixed'));
        }
        $this->assertNull($tags->regenerate($template, '/123/other', [640, 480], 'mixed'));
        $this->assertNull($tags->regenerate($template, '', [640, 480], 'mixed'));
    }

    public function test_historical_single_size_templates_upgrade_without_losing_the_master(): void
    {
        $tags = new GoogleVideoAdTag();
        foreach (['video_only', 'mixed'] as $format) {
            $legacy = 'https://pubads.g.doubleclick.net/gampad/ads?iu=%2F123%2Fvideo&env=vp&gdfp_req=1&output=vast&ad_type=video'
                .($format === 'video_only' ? '&vad_type=linear' : '')
                .'&unviewed_position_start=1&sz=320x180';
            $updated = $tags->regenerate($legacy, '/123/video', [320, 180], $format);
            $this->assertSame($tags->build('/123/video', [320, 180], $format), $updated);
            parse_str(parse_url($updated, PHP_URL_QUERY), $query);
            $this->assertSame('xml_vast4', $query['output']);
            $this->assertStringStartsWith('320x180|1x1|288x162|300x250|', $query['sz']);
            $this->assertCount(20, explode('|', $query['sz']));
            $this->assertNull($tags->regenerate($legacy.'&npa=1', '/123/video', [320, 180], $format));
        }
    }

    public function test_unknown_video_ad_format_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new GoogleVideoAdTag())->build('/123/video', [400, 225], 'display');
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
