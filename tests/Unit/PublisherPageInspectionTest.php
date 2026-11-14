<?php

namespace Tests\Unit;

use App\Services\Sites\PublisherPageInspection;
use PHPUnit\Framework\TestCase;

class PublisherPageInspectionTest extends TestCase
{
    public function test_only_the_expected_loader_origin_path_and_site_key_count_as_correct(): void
    {
        $check = new PublisherPageInspection;
        $loader = 'https://cdn.horusmedia.net/loader/hm-loader.min.js';
        $tag = '<script src="'.$loader.'" data-site-key="hm_expected"></script>';
        $this->assertSame('CORRECT_TAG_FOUND', $check->inspectHtml($tag, 'hm_expected', $loader));
        $this->assertSame('CORRECT_TAG_FOUND', $check->inspectHtml(str_replace('https:', '', $tag), 'hm_expected', $loader));
        $this->assertSame('WRONG_SITE_KEY', $check->inspectHtml($tag, 'hm_other', $loader));
        $this->assertSame('MISSING_SITE_KEY', $check->inspectHtml(str_replace(' data-site-key="hm_expected"', '', $tag), 'hm_expected', $loader));
        $this->assertSame('MULTIPLE_LOADER_TAGS', $check->inspectHtml($tag.$tag, 'hm_expected', $loader));
        $this->assertSame('LOADER_TAG_NOT_FOUND', $check->inspectHtml(str_replace('cdn.horusmedia.net', 'cdn.horusmedia.net.attacker.test', $tag), 'hm_expected', $loader));
        $this->assertSame('LOADER_TAG_NOT_FOUND', $check->inspectHtml('<!--'.$tag.'-->', 'hm_expected', $loader));
        $this->assertSame('LOADER_TAG_NOT_FOUND', $check->inspectHtml('<template>'.$tag.'</template>', 'hm_expected', $loader));
        $this->assertSame('LOADER_TAG_NOT_FOUND', $check->inspectHtml(str_replace('<script ', '<script type="text/plain" ', $tag), 'hm_expected', $loader));
        $this->assertSame('LOADER_TAG_NOT_FOUND', $check->inspectHtml(str_replace('https:', '', $tag), 'hm_expected', $loader, 'http'));
        foreach (['textarea', 'iframe', 'xmp', 'style', 'title', 'noembed', 'noframes'] as $container) {
            $this->assertSame('LOADER_TAG_NOT_FOUND', $check->inspectHtml('<'.$container.'>'.$tag.'</'.$container.'>', 'hm_expected', $loader));
            if ($container !== 'noframes') $this->assertSame('CORRECT_TAG_FOUND', $check->inspectHtml('<'.$container.'>'.$tag.'</'.$container.'>'.$tag, 'hm_expected', $loader));
        }
        $this->assertSame('UNREADABLE_HTML', $check->inspectHtml('', 'hm_expected', $loader));
    }
}
