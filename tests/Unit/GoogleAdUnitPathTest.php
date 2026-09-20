<?php

namespace Tests\Unit;

use App\Services\Demand\GoogleAdUnitPath;
use App\Services\Demand\GoogleRewardedAdUnitPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GoogleAdUnitPathTest extends TestCase
{
    public function test_accepts_plain_nested_and_mcm_paths_without_changing_their_case(): void
    {
        $parser = new GoogleAdUnitPath();
        foreach (['/123/site', '/123/site/Article_Top-1', '/123,456/site.net/display'] as $path) {
            $this->assertSame($path, $parser->parse(' '.$path.' '));
            $this->assertSame($path, (new GoogleRewardedAdUnitPath())->parse($path));
        }
    }

    public function test_leaves_provider_code_and_vast_urls_for_their_existing_parsers(): void
    {
        $parser = new GoogleAdUnitPath();
        foreach (['', 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video', '//provider.example/tag.js', '<script src="https://provider.example/tag.js"></script>'] as $input) {
            $this->assertNull($parser->parse($input));
        }
    }

    #[DataProvider('invalidPaths')]
    public function test_rejects_malformed_or_executable_paths(string $path): void
    {
        $this->expectException(RuntimeException::class);
        (new GoogleAdUnitPath())->parse($path);
    }

    public static function invalidPaths(): array
    {
        return [
            ['/Network_Code/unit'], ['/123/'], ['/123/unit/'], ['/123//unit'],
            ['/123/unit?callback=alert(1)'], ['/123/unit#fragment'], ['/123/unit<svg>'],
            ['/123/unit name'], ['/123/unit%2Fother'], ['/123,456,789/unit'],
            ['/123456789012345678901/unit'], ['/123/unit' . "\n" . '/other'],
            ['/123/'.str_repeat('a', 280)],
        ];
    }
}
