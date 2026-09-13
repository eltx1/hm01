<?php

namespace Tests\Unit;

use App\Services\Security\PublicProviderOriginValidator;
use PHPUnit\Framework\TestCase;

final class PublicProviderOriginValidatorTest extends TestCase
{
    public function test_hostname_is_rejected_when_any_dns_answer_is_private(): void
    {
        $validator = $this->validator([
            'ads.attacker.net' => ['8.8.8.8', '127.0.0.1'],
        ]);

        $this->assertNull($validator->canonicalOrigin('https://ads.attacker.net/ad.js'));
    }

    public function test_hostname_is_rejected_when_dns_resolution_returns_no_addresses(): void
    {
        $validator = $this->validator(['unresolved.example.net' => []]);

        $this->assertNull($validator->canonicalOrigin('https://unresolved.example.net/ad.js'));
    }

    public function test_public_dns_answers_preserve_non_default_port(): void
    {
        $validator = $this->validator([
            'cdn.provider.net' => ['1.1.1.1', '2606:4700:4700::1111'],
        ]);

        $this->assertSame(
            'https://cdn.provider.net:8443',
            $validator->canonicalOrigin('https://cdn.provider.net:8443/ad.js'),
        );
    }

    public function test_trailing_dot_is_normalized_before_control_plane_check(): void
    {
        $validator = $this->validator([]);

        $this->assertSame('app.horusmedia.net', $validator->normalizeHost('APP.HORUSMEDIA.NET.'));
        $this->assertNull($validator->canonicalOrigin('https://app.horusmedia.net./ad.js'));
        $this->assertNull($validator->canonicalOrigin('https://child.app.horusmedia.net./ad.js'));
    }

    public function test_public_ipv6_literal_keeps_rfc_brackets_and_port_without_dns(): void
    {
        $validator = $this->validator([]);

        $this->assertSame(
            'https://[2606:4700:4700::1111]:8443',
            $validator->canonicalOrigin('https://[2606:4700:4700::1111]:8443/ad.js'),
        );
    }

    /** @param array<string, array<int, string>> $answers */
    private function validator(array $answers): PublicProviderOriginValidator
    {
        return new class($answers) extends PublicProviderOriginValidator
        {
            public function __construct(private array $answers) {}

            protected function resolveAddresses(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        };
    }
}
