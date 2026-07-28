<?php

namespace Tests\Unit;

use App\Services\Identity\TwoFactorService;
use PHPUnit\Framework\TestCase;

class TwoFactorServiceTest extends TestCase
{
    public function test_totp_matches_rfc_6238_sha1_vector_reduced_to_six_digits(): void
    {
        $service = new TwoFactorService;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $service->currentCode($secret, 59));
        $this->assertTrue($service->verify($secret, '287082', 59));
        $this->assertFalse($service->verify($secret, '000000', 59));
    }
}
