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
            'private_key' => '-----BEGIN PRIVATE KEY-----private-material-123',
            'nested' => [
                'client_secret' => 'client-material-456',
                'authorization' => 'Bearer bearer-material-789',
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

        $encoded = json_encode($clean, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-material-123', $encoded);
        $this->assertStringNotContainsString('client-material-456', $encoded);
        $this->assertStringNotContainsString('bearer-material-789', $encoded);
    }
}
