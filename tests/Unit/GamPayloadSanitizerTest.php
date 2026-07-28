<?php

namespace Tests\Unit;

use App\Services\Gam\GamPayloadSanitizer;
use PHPUnit\Framework\TestCase;

class GamPayloadSanitizerTest extends TestCase
{
    public function test_it_redacts_nested_credentials_tokens_and_bearer_values(): void
    {
        $sanitizer = new GamPayloadSanitizer;
        $clean = $sanitizer->sanitize([
            'name' => 'Horus',
            'private_key' => '-----BEGIN PRIVATE KEY-----secret',
            'nested' => [
                'client_secret' => 'secret',
                'authorization' => 'Bearer very-secret-token',
                'safe' => 'visible',
            ],
            'free_text' => 'Bearer abc.def.ghi',
        ]);

        $this->assertSame('Horus', $clean['name']);
        $this->assertSame('[REDACTED]', $clean['private_key']);
        $this->assertSame('[REDACTED]', $clean['nested']['client_secret']);
        $this->assertSame('[REDACTED]', $clean['nested']['authorization']);
        $this->assertSame('visible', $clean['nested']['safe']);
        $this->assertSame('[REDACTED]', $clean['free_text']);
        $this->assertStringNotContainsString('secret', json_encode($clean, JSON_THROW_ON_ERROR));
    }
}
