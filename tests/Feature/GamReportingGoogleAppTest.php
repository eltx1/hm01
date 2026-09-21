<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Gam\GamManagedCredentials;
use App\Services\Gam\GamReportingGoogleApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class GamReportingGoogleAppTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/gam-platform-'.Str::ulid();
        File::makeDirectory($this->directory, 0700, true);
        config(['gam.onboarding.storage_path' => $this->directory, 'gam.onboarding.oauth_app_reference' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function configuration(): array
    {
        return ['web' => ['client_id' => '123.apps.googleusercontent.com', 'client_secret' => 'platform-private-secret',
            'redirect_uris' => [app(GamReportingGoogleApp::class)->redirectUri()]]];
    }

    public function test_platform_operator_can_provision_once_and_check_without_exposing_secrets(): void
    {
        $file = $this->directory.'/app.json';
        File::put($file, json_encode($this->configuration()));
        $this->artisan('gam:reporting-google', ['--file' => $file])
            ->expectsOutput('Platform Google application: configured.')->assertSuccessful();
        File::delete($file);
        $app = app(GamReportingGoogleApp::class);
        $this->assertTrue($app->ready());
        $this->assertSame('platform-private-secret', $app->credentials()['client_secret']);
        $this->assertStringNotContainsString('platform-private-secret', File::get($this->directory.'/oauth-app.enc'));
        $this->assertStringNotContainsString('platform-private-secret', AuditLog::all()->toJson());
        $this->assertSame(1, AuditLog::where('event', 'gam.reporting.oauth_app.configured')->count());
        $this->artisan('gam:reporting-google')->expectsOutput('Platform Google application: configured.')->assertSuccessful();
    }

    public function test_missing_corrupted_and_invalid_managed_configuration_are_not_reported_ready(): void
    {
        $app = app(GamReportingGoogleApp::class);
        $this->assertFalse($app->ready());
        File::put($this->directory.'/oauth-app.enc', 'corrupted-private-material');
        $this->assertFalse($app->ready());
        app(GamManagedCredentials::class)->write(['client_id' => 'invalid', 'client_secret' => 'private-secret'], 'oauth-app');
        $this->assertFalse($app->ready());
        $this->artisan('gam:reporting-google')->expectsOutput('Platform Google application: not configured.')->assertFailed();
    }

    public function test_explicit_private_configuration_is_validated_and_never_silently_replaced(): void
    {
        $app = app(GamReportingGoogleApp::class);
        $app->save($this->configuration());
        $file = $this->directory.'/deploy.json';
        config(['gam.onboarding.oauth_app_reference' => 'file:'.$file]);
        $this->assertFalse($app->ready());
        $json = $this->configuration();
        $json['web']['client_id'] = '456.apps.googleusercontent.com';
        File::put($file, json_encode($json));
        $this->assertSame('456.apps.googleusercontent.com', $app->credentials()['client_id']);
        $json['web']['redirect_uris'] = ['https://wrong.example/callback'];
        File::put($file, json_encode($json));
        $this->assertFalse($app->ready());
        $this->assertSame('123.apps.googleusercontent.com', app(GamManagedCredentials::class)->read('managed:oauth-app')['client_id']);
    }

    public function test_invalid_operator_file_cannot_replace_the_existing_app(): void
    {
        $app = app(GamReportingGoogleApp::class);
        $app->save($this->configuration());
        $file = $this->directory.'/invalid.json';
        File::put($file, '{invalid-private-json');
        $this->artisan('gam:reporting-google', ['--file' => $file])->assertFailed();
        $this->assertTrue($app->ready());
        $this->assertSame(1, AuditLog::where('event', 'gam.reporting.oauth_app.configured')->count());
    }
}
