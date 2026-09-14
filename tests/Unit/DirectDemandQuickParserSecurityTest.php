<?php

namespace Tests\Unit;

use App\Services\Demand\CustomThirdPartyTagConnector;
use App\Services\Demand\DirectTagRecipeParser;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class DirectDemandQuickParserSecurityTest extends TestCase
{
    public function test_parser_preserves_double_single_and_unquoted_script_and_container_attributes(): void
    {
        $parser = new DirectTagRecipeParser();
        $cases = [
            [
                '<script async src="https://cdn.example.com/double.js"></script><div id="double-zone"></div>',
                'https://cdn.example.com/double.js',
                'double-zone',
            ],
            [
                "<script async src='https://cdn.example.com/single.js'></script><div id='single-zone'></div>",
                'https://cdn.example.com/single.js',
                'single-zone',
            ],
            [
                '<script async src=https://cdn.example.com/unquoted.js></script><div id=unquoted-zone></div>',
                'https://cdn.example.com/unquoted.js',
                'unquoted-zone',
            ],
        ];

        foreach ($cases as [$markup, $expectedUrl, $expectedId]) {
            $parsed = $parser->parse($markup);

            $this->assertSame([], $parsed['securityWarnings']);
            $this->assertFalse($parsed['containsSensitiveMaterial']);
            $this->assertSame($expectedUrl, data_get($parsed, 'detectedScripts.0.url'));
            $this->assertTrue((bool) data_get($parsed, 'detectedScripts.0.async'));
            $this->assertSame($expectedId, data_get($parsed, 'detectedContainers.0.id'));
        }
    }

    public function test_parser_normalizes_protocol_relative_single_quoted_script_url(): void
    {
        $parsed = (new DirectTagRecipeParser())->parse(
            "<script src='//cdn.example.com/provider.js'></script><div id='provider-zone'></div>",
        );

        $this->assertSame('https://cdn.example.com/provider.js', data_get($parsed, 'detectedScripts.0.url'));
        $this->assertSame('provider-zone', data_get($parsed, 'detectedContainers.0.id'));
        $this->assertSame([], $parsed['securityWarnings']);
    }

    public function test_publication_script_url_extraction_uses_the_same_parser_for_all_attribute_forms(): void
    {
        $connector = (new ReflectionClass(CustomThirdPartyTagConnector::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CustomThirdPartyTagConnector::class, 'externalScriptUrls');
        $method->setAccessible(true);

        $urls = $method->invoke(
            $connector,
            "<script src='https://cdn.example.com/single.js'></script>"
                .'<script src=https://cdn.example.com/unquoted.js></script>'
                .'<script src="//cdn.example.com/protocol-relative.js"></script>'
                .'<div id="provider-zone"></div>',
        );

        $this->assertSame([
            'https://cdn.example.com/single.js',
            'https://cdn.example.com/unquoted.js',
            'https://cdn.example.com/protocol-relative.js',
        ], $urls);
    }

    public function test_quick_navigation_guard_blocks_global_this_dot_and_bracket_forms(): void
    {
        $connector = (new ReflectionClass(CustomThirdPartyTagConnector::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CustomThirdPartyTagConnector::class, 'assertNoQuickSelfNavigation');
        $method->setAccessible(true);

        $unsafePrograms = [
            "<script>globalThis.location='https://app.horusmedia.net/private';</script>",
            "<script>globalThis.location.href='https://127.0.0.1/private';</script>",
            "<script>globalThis['location']['href']='https://127.0.0.1/private';</script>",
            "<script>globalThis.location.replace('https://127.0.0.1/private');</script>",
            "<script>globalThis['location']['assign']('https://127.0.0.1/private');</script>",
        ];

        foreach ($unsafePrograms as $program) {
            try {
                $method->invoke($connector, $program);
                $this->fail('Expected Quick self-navigation to be rejected: '.$program);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('cannot navigate', $exception->getMessage());
            }
        }

        $method->invoke($connector, '<script>globalThis.providerQueue = globalThis.providerQueue || [];</script>');
        $this->addToAssertionCount(1);
    }
}
