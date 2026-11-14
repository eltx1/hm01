<?php

namespace Tests\Feature;

use App\Services\Campaigns\RemoteUrlSafetyValidator;
use App\Services\Network\Contracts\DnsResolver;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemoteUrlGlobalAddressTest extends TestCase
{
    private function validator(array $addresses): RemoteUrlSafetyValidator
    {
        return new RemoteUrlSafetyValidator(new class($addresses) implements DnsResolver {
            public function __construct(private readonly array $items) {}
            public function addresses(string $host): array { return $this->items; }
        });
    }

    public function test_special_non_global_addresses_cannot_be_used_for_server_side_checks(): void
    {
        foreach (['100.64.0.1', '192.0.0.1', '198.18.0.1', '192.0.2.1', '203.0.113.1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', '2001:db8::1'] as $address) {
            try {
                $this->validator([$address])->publicAddresses('https://publisher.example.test/page', 'page_url');
                $this->fail('Non-global address must be rejected: '.$address);
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('page_url', $error->errors());
            }
        }
    }

    public function test_mixed_answers_fail_closed_but_globally_routable_addresses_remain_supported(): void
    {
        $public = ['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111'];
        $this->assertSame($public, $this->validator($public)->publicAddresses('https://publisher.example.test/page', 'page_url'));
        $this->expectException(ValidationException::class);
        $this->validator(['8.8.8.8', '100.64.0.1'])->publicAddresses('https://publisher.example.test/page', 'page_url');
    }
}
