<?php

namespace Tests;

use App\Services\Network\Contracts\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Blade feature tests must not depend on production assets already
        // existing in the workspace. The frontend build is verified by its
        // own CI job and production release step.
        $this->withoutVite();

        // RFC example domains are used throughout feature fixtures. Give them
        // a deterministic public address so SSRF-safe fetch tests do not depend
        // on external DNS. Tests for private/unsafe DNS explicitly override this.
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }
}
