<?php

namespace Tests\Unit;

use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Demand\DemandSecretResolver;
use ReflectionMethod;
use Tests\TestCase;

class DemandConfigurationSecurityTest extends TestCase
{
    public function test_public_native_tag_exposes_only_allowlisted_structured_fields_and_data_attributes(): void
    {
        $builder = new DemandConfigurationBuilder(
            new DemandConnectorManager(new DemandSecretResolver())
        );
        $method = new ReflectionMethod($builder, 'sanitizePublicTag');

        $tag = $method->invoke($builder, [
            'scriptUrl' => 'https://jsc.mgid.com/publisher.example/widget.js',
            'containerId' => 'widget"><script>alert(1)</script>',
            'containerClass' => 'native" onclick="steal()',
            'attributes' => [
                'data-widget' => 'approved-public-value',
                'data-token' => 'must-not-leak',
                'src' => 'https://evil.example/override.js',
                'onload' => 'steal()',
                'href' => 'javascript:steal()',
                'aria-label' => 'not-required-by-provider',
            ],
            'renderTimeoutMs' => 999999,
            'successSelector' => '#widget-ready',
            'assumeLoadedIsSuccess' => true,
            'credential_reference' => 'env:MGID_API_TOKEN',
            'privateApiUrl' => 'https://private.example/report',
        ]);

        $this->assertSame('https://jsc.mgid.com/publisher.example/widget.js', $tag['scriptUrl']);
        $this->assertSame(['data-widget' => 'approved-public-value'], $tag['attributes']);
        $this->assertSame(10000, $tag['renderTimeoutMs']);
        $this->assertSame('#widget-ready', $tag['successSelector']);
        $this->assertTrue($tag['assumeLoadedIsSuccess']);
        $this->assertArrayNotHasKey('credential_reference', $tag);
        $this->assertArrayNotHasKey('privateApiUrl', $tag);
        $this->assertStringNotContainsString('<', $tag['containerId']);
        $this->assertStringNotContainsString('"', $tag['containerClass']);
    }
}
