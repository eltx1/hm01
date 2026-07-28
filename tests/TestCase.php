<?php

namespace Tests;

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
    }
}
