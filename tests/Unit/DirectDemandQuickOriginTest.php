<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\DirectDemandQuickMonetizeController;
use ReflectionMethod;
use Tests\TestCase;

final class DirectDemandQuickOriginTest extends TestCase
{
    public function test_public_ipv6_provider_origin_keeps_url_brackets_and_non_default_port(): void
    {
        $method = new ReflectionMethod(DirectDemandQuickMonetizeController::class, 'scriptOrigins');
        $method->setAccessible(true);

        $origins = $method->invoke(
            new DirectDemandQuickMonetizeController(),
            [['url' => 'https://[2606:4700:4700::1111]:8443/ad.js']],
        );

        $this->assertSame(
            ['https://[2606:4700:4700::1111]:8443'],
            $origins,
        );
    }
}
