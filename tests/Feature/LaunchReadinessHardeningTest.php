<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use App\Services\Network\Contracts\DnsResolver;
use Database\Seeders\IdentityAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class LaunchReadinessHardeningTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_identity_reseed_preserves_settings_permissions_for_intended_roles(): void
    {
        $this->seedIdentity();
        $this->seed(IdentityAccessSeeder::class);

        $operations = Role::query()->whereNull('organization_id')->where('name', RoleName::OperationsAdmin->value)->firstOrFail();
        $adOps = Role::query()->whereNull('organization_id')->where('name', RoleName::AdOpsAdmin->value)->firstOrFail();
        $finance = Role::query()->whereNull('organization_id')->where('name', RoleName::FinanceAdmin->value)->firstOrFail();
        $publisher = Role::query()->whereNull('organization_id')->where('name', RoleName::PublisherAdmin->value)->firstOrFail();

        $this->assertTrue($operations->fresh()->permissions()->where('name', 'settings.view')->exists());
        $this->assertTrue($operations->fresh()->permissions()->where('name', 'settings.manage')->exists());
        $this->assertTrue($adOps->fresh()->permissions()->where('name', 'settings.view')->exists());
        $this->assertFalse($adOps->fresh()->permissions()->where('name', 'settings.manage')->exists());
        $this->assertTrue($finance->fresh()->permissions()->where('name', 'settings.view')->exists());
        $this->assertFalse($publisher->fresh()->permissions()->whereIn('name', ['settings.view', 'settings.manage'])->exists());
    }

    public function test_global_internal_routes_reject_publisher_even_if_permissions_are_misassigned(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Hostile Publisher');
        $user = $this->makeUser($organization, RoleName::PublisherAdmin);
        $role = Role::query()->whereNull('organization_id')->where('name', RoleName::PublisherAdmin->value)->firstOrFail();
        $permissionIds = Permission::query()->whereIn('name', [
            'settings.view', 'settings.manage', 'operations.view', 'operations.manage', 'audit.view',
        ])->pluck('id');
        $role->permissions()->syncWithoutDetaching($permissionIds);
        $user->unsetRelation('roles');

        $this->actingAs($user)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.operations.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.update', ['key' => 'reporting.retry_delay_minutes']), ['value' => 10])->assertForbidden();
        $this->actingAs($user)->post(route('admin.operations.controls'), [])->assertForbidden();
    }

    public function test_remote_url_safety_rejects_loopback_private_link_local_metadata_and_unsafe_ports(): void
    {
        foreach ([
            ['host' => 'loopback-v4.example', 'address' => '127.0.0.1'],
            ['host' => 'private-v4.example', 'address' => '10.20.30.40'],
            ['host' => 'metadata.example', 'address' => '169.254.169.254'],
            ['host' => 'loopback-v6.example', 'address' => '::1'],
            ['host' => 'linklocal-v6.example', 'address' => 'fe80::1'],
            ['host' => 'private-v6.example', 'address' => 'fc00::1'],
        ] as $case) {
            $validator = new RemoteUrlSafetyValidator(new class($case['host'], $case['address']) implements DnsResolver {
                public function __construct(private string $host, private string $address) {}
                public function addresses(string $host): array
                {
                    return $host === $this->host ? [$this->address] : [];
                }
            });

            try {
                $validator->publicAddresses('https://'.$case['host'].'/ads.txt', 'ads_txt_url');
                $this->fail('Unsafe target was accepted: '.$case['address']);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $validator = new RemoteUrlSafetyValidator(new class implements DnsResolver {
            public function addresses(string $host): array { return ['8.8.8.8']; }
        });
        foreach (['http://localhost/ads.txt', 'https://public.example:8080/ads.txt', 'ftp://public.example/ads.txt'] as $url) {
            try {
                $validator->publicAddresses($url, 'ads_txt_url');
                $this->fail('Unsafe URL form was accepted: '.$url);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(['8.8.8.8'], $validator->publicAddresses('https://public.example/ads.txt', 'ads_txt_url'));
    }
}
