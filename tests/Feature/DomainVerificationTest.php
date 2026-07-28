<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\VerificationMethod;
use App\Services\Sites\DnsResolver;
use App\Services\Sites\DomainSafetyValidator;
use App\Services\Sites\DomainVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class DomainVerificationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_meta_tag_domain_verification_succeeds_and_is_audited(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user, ['primary_domain' => 'publisher.example']);
        $domain = $site->domains()->firstOrFail();
        $this->mock(DomainSafetyValidator::class, fn (MockInterface $mock) => $mock->shouldReceive('assertSafe')->once()->with($domain->domain)->andReturn(['93.184.216.34']));
        Http::fake(['*' => Http::response('<html><meta name="horus-site-verification" content="'.$domain->verification_token.'"></html>')]);

        $this->actingAs($user)->post(route('publisher.sites.domains.verify', [$site, $domain]), ['method' => 'META_TAG'])->assertRedirect();

        $this->assertSame('VERIFIED', $domain->fresh()->verification_status);
        $this->assertDatabaseHas('site_verifications', ['site_domain_id' => $domain->id, 'method' => 'META_TAG', 'status' => 'VERIFIED']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.domain.verification_attempted', 'auditable_id' => $domain->id]);
    }

    public function test_all_verification_methods_produce_complete_instructions(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $domain = $this->makeSiteFor($this->makePublisherFor($user), $user)->domains()->firstOrFail();
        $service = app(DomainVerificationService::class);

        foreach (VerificationMethod::cases() as $method) {
            $this->assertNotEmpty($service->instructions($domain, $method));
        }
        $this->assertStringContainsString('horus-site-verification=', $service->expectedValue($domain, VerificationMethod::DnsTxt));
    }

    public function test_text_file_and_dns_txt_verification_succeed(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($user), $user, ['primary_domain' => 'publisher.example']);
        $domain = $site->domains()->firstOrFail();
        $this->mock(DomainSafetyValidator::class, fn (MockInterface $mock) => $mock->shouldReceive('assertSafe')->once()->andReturn(['93.184.216.34']));
        Http::fake(['*' => Http::response($domain->verification_token)]);
        $this->actingAs($user)->post(route('publisher.sites.domains.verify', [$site, $domain]), ['method' => 'TEXT_FILE'])->assertRedirect();

        $domain->update(['verification_status' => 'PENDING', 'verified_at' => null]);
        $this->mock(DnsResolver::class, fn (MockInterface $mock) => $mock->shouldReceive('textRecords')->once()->andReturn(['horus-site-verification='.$domain->verification_token]));
        $this->post(route('publisher.sites.domains.verify', [$site, $domain]), ['method' => 'DNS_TXT'])->assertRedirect();

        $this->assertDatabaseHas('site_verifications', ['site_domain_id' => $domain->id, 'method' => 'TEXT_FILE', 'status' => 'VERIFIED']);
        $this->assertDatabaseHas('site_verifications', ['site_domain_id' => $domain->id, 'method' => 'DNS_TXT', 'status' => 'VERIFIED']);
    }

    public function test_horus_administrator_can_manually_verify_domain(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $domain = $site->domains()->firstOrFail();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.domains.manual-verify', [$site, $domain]), ['reason' => 'Ownership document reviewed'])->assertRedirect();

        $this->assertSame('VERIFIED', $domain->fresh()->verification_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.domain.manually_verified', 'auditable_id' => $domain->id]);
    }

    public function test_domain_safety_rejects_private_addresses(): void
    {
        $resolver = $this->mock(DnsResolver::class, fn (MockInterface $mock) => $mock->shouldReceive('addresses')->once()->andReturn(['127.0.0.1']));

        $this->expectException(ValidationException::class);
        (new DomainSafetyValidator($resolver))->assertSafe('example.com');
    }
}
