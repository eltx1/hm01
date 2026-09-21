<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Gam\GamReportingGoogleApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class GamReportingGoogleAppUploadTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/gam-upload-'.Str::ulid();
        config(['gam.onboarding.storage_path' => $this->directory, 'gam.onboarding.oauth_app_reference' => null]);
        Http::preventStrayRequests();
        $this->seedIdentity();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function operator(): void
    {
        $user = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $this->actingAs($user)->withSession(['two_factor_passed_at' => now()->timestamp]);
    }

    private function configuration(): array
    {
        return ['web' => ['client_id' => '123.apps.googleusercontent.com', 'client_secret' => 'private-upload-secret',
            'redirect_uris' => [app(GamReportingGoogleApp::class)->redirectUri()]]];
    }

    private function upload(?array $json = null): array
    {
        return ['google_app' => UploadedFile::fake()->createWithContent('google-client.json', json_encode($json ?? $this->configuration()))];
    }

    public function test_operator_can_upload_privately_and_repeat_without_replacing_the_application(): void
    {
        $this->operator();
        $this->get(route('admin.gam.reporting.google-app.show'))->assertOk()
            ->assertSee('multipart/form-data', false)->assertSee('Save Google application')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post(route('admin.gam.reporting.google-app.store'), $this->upload())
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.gam.reporting.google-app.show'));
        $encrypted = File::get($this->directory.'/oauth-app.enc');
        $this->assertStringNotContainsString('private-upload-secret', $encrypted);
        $this->assertSame('private-upload-secret', app(GamReportingGoogleApp::class)->credentials()['client_secret']);
        $audit = AuditLog::where('event', 'gam.reporting.oauth_app.configured')->sole();
        $this->assertSame(auth()->id(), $audit->actor_id);
        $this->assertStringNotContainsString('private-upload-secret', $audit->toJson());
        $different = $this->configuration();
        $different['web']['client_id'] = '456.apps.googleusercontent.com';
        $this->post(route('admin.gam.reporting.google-app.store'), $this->upload($different))->assertSessionHasNoErrors();
        $this->assertSame($encrypted, File::get($this->directory.'/oauth-app.enc'));
        $this->assertSame(1, AuditLog::where('event', 'gam.reporting.oauth_app.configured')->count());
        $page = $this->get(route('admin.gam.reporting.google-app.show'))->assertOk()
            ->assertSee('Google application configured')->assertSee('Choose a website')
            ->assertDontSee('Save Google application')->assertDontSee('private-upload-secret')
            ->assertDontSee('123.apps.googleusercontent.com');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertDatabaseCount('gam_connections', 0);
        $this->assertDatabaseCount('site_gam_report_bindings', 0);
    }

    public function test_guests_publishers_and_staff_without_settings_permission_cannot_upload(): void
    {
        $url = route('admin.gam.reporting.google-app.show');
        $this->get($url)->assertRedirect(route('admin.login'));
        $this->post($url, $this->upload())->assertRedirect(route('admin.login'));
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->actingAs($publisher)->get($url)->assertForbidden();
        $this->post($url, $this->upload())->assertForbidden();
        $this->operator();
        $role = Role::where('name', RoleName::SuperAdmin->value)->whereNull('organization_id')->firstOrFail();
        $role->permissions()->detach(Permission::where('name', 'settings.manage')->firstOrFail());
        auth()->user()->unsetRelation('roles');
        $this->get($url)->assertForbidden();
        $this->post($url, $this->upload())->assertForbidden();
        $this->assertFalse(app(GamReportingGoogleApp::class)->ready());
    }

    public function test_two_factor_verification_is_required_before_upload(): void
    {
        $this->operator();
        $this->withSession(['two_factor_passed_at' => 0]);
        $this->post(route('admin.gam.reporting.google-app.store'), $this->upload())
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertFalse(app(GamReportingGoogleApp::class)->ready());
    }

    public function test_invalid_json_wrong_callback_and_oversized_uploads_never_activate_or_leak_material(): void
    {
        $this->operator();
        $url = route('admin.gam.reporting.google-app.store');
        $this->from($url)->post($url, ['google_app' => UploadedFile::fake()->createWithContent('private-filename.json', '{private-invalid-material')])
            ->assertSessionHasErrors('google_app');
        $this->assertStringNotContainsString('private-invalid-material', json_encode(session()->all()));
        $this->assertStringNotContainsString('private-filename', json_encode(session()->all()));
        $json = $this->configuration();
        $json['web']['redirect_uris'] = ['https://wrong.example/callback'];
        $this->post($url, $this->upload($json))->assertSessionHasErrors('gam_account');
        $this->post($url, ['google_app' => UploadedFile::fake()->create('client.json', 33)])->assertSessionHasErrors('google_app');
        $this->assertFalse(app(GamReportingGoogleApp::class)->ready());
        $this->assertSame(0, AuditLog::where('event', 'gam.reporting.oauth_app.configured')->count());
        $this->assertFileDoesNotExist($this->directory.'/oauth-app.enc');
    }

    public function test_server_managed_configuration_cannot_be_shadowed_by_an_upload(): void
    {
        $this->operator();
        config(['gam.onboarding.oauth_app_reference' => 'file:'.$this->directory.'/missing.json']);
        $this->get(route('admin.gam.reporting.google-app.show'))->assertOk()
            ->assertSee('Server configuration needs attention')->assertDontSee('Save Google application');
        $this->post(route('admin.gam.reporting.google-app.store'), $this->upload())->assertSessionHasErrors('google_app');
        $this->assertFileDoesNotExist($this->directory.'/oauth-app.enc');
    }
}
