<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_tenant_admin_can_update_own_branding_and_upload_logo(): void
    {
        Storage::fake('public');
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($organization, RoleName::PublisherAdmin);

        $this->actingAs($user)->put(route('account.branding.update'), [
            'dashboard_title' => 'Publisher Console', 'primary_color' => '#123456', 'support_email' => 'support@publisher.test', 'logo' => UploadedFile::fake()->image('logo.png', 200, 100),
        ])->assertRedirect();

        $organization->refresh();
        $this->assertSame('Publisher Console', $organization->dashboard_title);
        Storage::disk('public')->assertExists($organization->logo_path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'organization.branding.updated', 'organization_id' => $organization->id]);
    }

    public function test_tenant_cannot_edit_another_organization_branding(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $other = $this->makeOrganization(OrganizationType::Publisher);
        $this->actingAs($user)->put(route('admin.organizations.branding.update', $other), ['dashboard_title' => 'Unsafe'])->assertForbidden();
    }

    public function test_tenant_viewer_cannot_edit_branding(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($organization, RoleName::PublisherViewer);

        $this->actingAs($user)->put(route('account.branding.update'), ['dashboard_title' => 'Unsafe'])->assertForbidden();
    }
}
