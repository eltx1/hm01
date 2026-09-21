<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Models\AuditLog;
use App\Models\ConfigVersion;
use App\Models\GamConnection;
use App\Models\GamCredential;
use App\Models\SiteGamReportBinding;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Gam\GamConnectionService;
use App\Services\Gam\GamManagedCredentials;
use App\Services\Gam\GamOAuthTokenProvider;
use App\Services\Gam\GamOfficialSoapTransport;
use App\Services\Gam\GamOperationExecutor;
use App\Services\Gam\GamReportingOnboarding;
use App\Services\Gam\GamRestConnector;
use App\Services\Gam\GamSecretResolver;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Credentials\UserRefreshCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class GamReportingOnboardingTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private object $google;

    private string $privateDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateDirectory = sys_get_temp_dir().'/gam-onboarding-'.Str::ulid();
        config(['gam.onboarding.storage_path' => $this->privateDirectory]);
        Http::preventStrayRequests();
        $this->google = new class implements GamSoapTransportInterface
        {
            public array $calls = [];

            public array $networks = [['networkCode' => '101', 'displayName' => 'First network', 'currencyCode' => 'USD', 'timeZone' => 'Africa/Cairo']];

            public ?string $error = null;

            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = compact('service', 'method', 'payload');
                if ($this->error) {
                    throw new \RuntimeException($this->error);
                }

                return match ($method) {
                    'getAllNetworks' => count($this->networks) === 1 ? $this->networks[0] : $this->networks,
                    'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => 'USD', 'timeZone' => 'Africa/Cairo'],
                    'getAdUnitsByStatement' => ['results' => [['id' => '555', 'name' => 'Site unit', 'adUnitCode' => 'site_unit']]],
                    default => throw new \RuntimeException('Unexpected call '.$method),
                };
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $this->google);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateDirectory);
        parent::tearDown();
    }

    private function context(): array
    {
        $this->seedIdentity();
        $org = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus');
        $admin = $this->makeUser($org, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        return [$admin, $site, $user];
    }

    private function appJson(): array
    {
        return ['web' => ['client_id' => '123.apps.googleusercontent.com', 'client_secret' => 'private-client-secret', 'redirect_uris' => [app(GamReportingOnboarding::class)->redirectUri()]]];
    }

    private function tokenResponses(string $subject = 'first-person', string $email = 'admin@example.test', string $refresh = 'private-refresh-token'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'private-access-token', 'refresh_token' => $refresh, 'scope' => config('gam.oauth.scope').' openid email']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => $subject, 'email' => $email]),
        ]);
    }

    private function start($admin, $site): array
    {
        app(GamReportingOnboarding::class)->saveApp($this->appJson(), $admin);
        $response = $this->post(route('admin.sites.reporting.accounts.start', $site))->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function completeConsent(string $state)
    {
        return $this->get(route('admin.gam.reporting.oauth.callback').'?'.http_build_query(['state' => $state, 'code' => 'private-authorization-code']));
    }

    public function test_first_account_page_has_setup_and_secure_file_upload_without_server_paths(): void
    {
        [, $site, $publisher] = $this->context();
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('Connect your first Ad Manager account');
        $this->get(route('admin.sites.reporting.accounts.show', $site))->assertOk()
            ->assertSee('Set up Google sign-in once')->assertSee(app(GamReportingOnboarding::class)->redirectUri())
            ->assertSee('Service-account JSON key')->assertDontSee('credential_reference');
        $this->actingAs($publisher)->get(route('admin.sites.reporting.accounts.show', $site))->assertForbidden();
        $this->post(route('admin.sites.reporting.accounts.start', $site))->assertForbidden();
    }

    public function test_app_upload_starts_google_consent_with_pkce_and_no_secret_in_redirect_or_audit(): void
    {
        [, $site] = $this->context();
        $response = $this->post(route('admin.sites.reporting.accounts.setup', $site), ['google_app' => UploadedFile::fake()->createWithContent('client.json', json_encode($this->appJson()))])
            ->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent select_account', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(43, strlen($query['code_challenge']));
        $this->assertSame(64, strlen($query['state']));
        $this->assertStringNotContainsString('private-client-secret', $response->headers->get('Location'));
        $this->assertStringNotContainsString('private-client-secret', file_get_contents($this->privateDirectory.'/oauth-app.enc'));
        $this->assertStringNotContainsString('private-client-secret', AuditLog::all()->toJson());
        $cached = Cache::get('gam:onboarding:state:'.hash('sha256', $query['state']));
        $this->assertStringNotContainsString('private-client-secret', $cached);
        $this->assertSame(0600, fileperms($this->privateDirectory.'/oauth-app.enc') & 0777);
    }

    public function test_one_network_is_selected_automatically_and_site_ad_unit_can_be_connected_next(): void
    {
        [$admin, $site] = $this->context();
        $serving = $this->makeGamConnection($admin->organization, $admin);
        $before = $site->fresh()->getAttributes();
        $configs = ConfigVersion::count();
        $this->tokenResponses();
        $start = $this->start($admin, $site);
        $this->completeConsent($start['state'])->assertSessionHasNoErrors()->assertRedirect(route('admin.sites.show', $site).'#reporting');
        $connection = GamConnection::withoutGlobalScopes()->where('is_reporting_only', true)->sole();
        $this->assertSame($connection->id, session('reporting_gam_connection_id'));
        $this->assertFalse($connection->is_primary);
        $this->assertSame('101', $connection->network_code);
        $this->assertTrue($serving->fresh()->is_primary);
        $this->assertSame($before, $site->fresh()->getAttributes());
        $this->assertSame($configs, ConfigVersion::count());
        $this->assertDatabaseCount('site_gam_report_bindings', 0);
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('Connect another Ad Manager account')->assertSee('First network');
        $material = app(GamSecretResolver::class)->readJson($connection->credential->reference);
        $credentialMethod = new \ReflectionMethod(GamOfficialSoapTransport::class, 'credential');
        $this->assertInstanceOf(UserRefreshCredentials::class, $credentialMethod->invoke(app(GamOfficialSoapTransport::class), $connection));
        $this->assertSame('private-refresh-token', $material['refresh_token']);
        $this->assertStringNotContainsString('private-refresh-token', GamCredential::withoutGlobalScopes()->get()->toJson());
        $this->assertStringNotContainsString('private-refresh-token', file_get_contents($this->privateDirectory.'/'.$connection->id.'.enc'));
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $start['code_challenge']);
        $this->post(route('admin.sites.reporting.gam.store', $site), ['gam_connection_id' => $connection->id, 'ad_unit' => '555'])->assertSessionHasNoErrors();
        $this->assertSame($connection->id, SiteGamReportBinding::withoutGlobalScopes()->sole()->gam_connection_id);
    }

    public function test_several_networks_can_be_added_together_and_retried_without_duplicates(): void
    {
        [$admin, $site] = $this->context();
        $this->google->networks[] = ['networkCode' => '202', 'displayName' => '<script>bad()</script>', 'currencyCode' => 'EUR', 'timeZone' => 'Europe/Paris'];
        $this->tokenResponses();
        $response = $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasNoErrors();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()', false);
        $this->assertDatabaseCount('gam_connections', 0);
        $data = ['flow' => $query['flow'], 'networks' => ['101', '202']];
        $this->post(route('admin.sites.reporting.accounts.connect', $site), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.sites.reporting.accounts.connect', $site), $data)->assertSessionHasNoErrors();
        $this->assertSame(['101', '202'], GamConnection::withoutGlobalScopes()->orderBy('network_code')->pluck('network_code')->all());
        $this->assertSame(2, GamCredential::withoutGlobalScopes()->count());
    }

    public function test_second_google_account_is_independent_and_reconnecting_first_keeps_ids_and_serving_primary(): void
    {
        [$admin, $site] = $this->context();
        $serving = $this->makeGamConnection($admin->organization, $admin, ['network_code' => '101']);
        $this->tokenResponses();
        $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasNoErrors();
        $first = GamConnection::withoutGlobalScopes()->where('is_reporting_only', true)->sole();
        $this->tokenResponses('second-person', 'second@example.test', 'second-refresh');
        $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasNoErrors();
        $this->assertSame(2, GamConnection::withoutGlobalScopes()->where('is_reporting_only', true)->count());
        $this->tokenResponses('first-person', 'admin@example.test', 'rotated-refresh');
        $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('gam_connections', 3);
        $this->assertSame('rotated-refresh', app(GamSecretResolver::class)->readJson($first->fresh()->credential->reference)['refresh_token']);
        $this->assertSame($serving->id, GamConnection::withoutGlobalScopes()->where('is_primary', true)->sole()->id);
    }

    public function test_state_is_session_bound_expires_and_cannot_be_replayed(): void
    {
        [$admin, $site] = $this->context();
        $this->tokenResponses();
        $state = $this->start($admin, $site)['state'];
        $browser = session('gam_onboarding_browser');
        $this->withSession(['gam_onboarding_browser' => 'wrong-browser'])->completeConsent($state)->assertSessionHasErrors('gam_account');
        Http::assertNothingSent();
        $this->withSession(['gam_onboarding_browser' => $browser])->completeConsent($state)->assertRedirect(route('admin.sites.show', $site).'#reporting');
        $this->completeConsent($state)->assertSessionHasErrors('gam_account');
        $this->assertDatabaseCount('gam_connections', 1);
        $next = $this->start($admin, $site)['state'];
        $this->travel(11)->minutes();
        $this->completeConsent($next)->assertSessionHasErrors('gam_account');
        Http::assertSentCount(2);
    }

    public function test_denial_missing_refresh_token_and_declined_gam_scope_do_not_create_accounts(): void
    {
        [$admin, $site] = $this->context();
        $state = $this->start($admin, $site)['state'];
        $this->get(route('admin.gam.reporting.oauth.callback').'?'.http_build_query(['state' => $state, 'error' => 'access_denied']))->assertSessionHasErrors('gam_account');
        Http::assertNothingSent();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'secret-access'])]);
        $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasErrors('gam_account');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'scope' => 'openid email'])]);
        $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasErrors('gam_account');
        $this->assertDatabaseCount('gam_connections', 0);
    }

    public function test_network_selection_rejects_foreign_site_unoffered_network_and_expired_flow(): void
    {
        [$admin, $site, $publisher] = $this->context();
        $otherSite = $this->makeSiteFor($site->publisher, $publisher);
        $this->google->networks[] = ['networkCode' => '202', 'displayName' => 'Other'];
        $this->tokenResponses();
        $response = $this->completeConsent($this->start($admin, $site)['state']);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->post(route('admin.sites.reporting.accounts.connect', $otherSite), ['flow' => $query['flow'], 'networks' => ['101']])->assertSessionHasErrors('gam_account');
        $this->post(route('admin.sites.reporting.accounts.connect', $site), ['flow' => $query['flow'], 'networks' => ['999']])->assertSessionHasErrors('gam_account');
        $this->travel(21)->minutes();
        $this->post(route('admin.sites.reporting.accounts.connect', $site), ['flow' => $query['flow'], 'networks' => ['101']])->assertSessionHasErrors('gam_account');
        $this->assertDatabaseCount('gam_connections', 0);
    }

    public function test_service_account_upload_requires_no_oauth_app_and_ignores_supplied_token_endpoints(): void
    {
        [, $site] = $this->context();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $material = ['type' => 'service_account', 'client_email' => 'reports@project.iam.gserviceaccount.com', 'private_key' => $privateKey, 'token_uri' => 'http://127.0.0.1/steal'];
        $this->post(route('admin.sites.reporting.accounts.upload', $site), ['service_account' => UploadedFile::fake()->createWithContent('key.json', json_encode($material))])->assertSessionHasNoErrors();
        $connection = GamConnection::withoutGlobalScopes()->sole();
        $stored = app(GamSecretResolver::class)->readJson($connection->credential->reference);
        $credentialMethod = new \ReflectionMethod(GamOfficialSoapTransport::class, 'credential');
        $this->assertInstanceOf(ServiceAccountCredentials::class, $credentialMethod->invoke(app(GamOfficialSoapTransport::class), $connection));
        $this->assertSame('https://oauth2.googleapis.com/token', $stored['token_uri']);
        $this->assertSame($privateKey, $stored['private_key']);
        $this->assertFalse(app(GamManagedCredentials::class)->hasApp());
        $this->assertStringNotContainsString($privateKey, file_get_contents($this->privateDirectory.'/'.$connection->id.'.enc'));
        Http::assertNothingSent();
    }

    public function test_reporting_accounts_cannot_become_serving_fallbacks_or_write_inventory(): void
    {
        [$admin, $site] = $this->context();
        $connection = $this->makeGamConnection($admin->organization, $admin, ['is_reporting_only' => true, 'is_primary' => true]);
        $site->update(['serving_mode' => ServingMode::HorusGam]);
        $this->assertNull(app(GamConnectionResolver::class)->resolve($site));
        $site->update(['gam_connection_id' => $connection->id]);
        $this->assertNull(app(GamConnectionResolver::class)->resolve($site));
        foreach ([fn () => app(GamConnectionService::class)->setPrimary($connection, $admin), fn () => app(GamConnectionService::class)->assignToSite($site, $connection, $admin, 'test'), fn () => app(GamConnectionService::class)->update($connection, ['is_primary' => true], $admin)] as $attempt) {
            try {
                $attempt();
                $this->fail('Reporting account was accepted for serving.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $called = false;
        $result = app(GamOperationExecutor::class)->execute($connection, 'inventory.create', 'InventoryService', 'createAdUnits', [], function () use (&$called) {
            $called = true;

            return [];
        }, ['write' => true, 'dry_run' => false]);
        $this->assertFalse($result->success);
        $this->assertFalse($called);
        $rest = new GamRestConnector($connection, app(GamOAuthTokenProvider::class), app(GamOperationExecutor::class));
        $this->assertFalse($rest->createAdUnit(['displayName' => 'Must not create'], ['write' => false, 'dry_run' => false])->success);
        Http::assertNothingSent();
        $this->expectExceptionMessage('reports only');
        app(GamOfficialSoapTransport::class)->call($connection, 'InventoryService', 'createAdUnits');
    }

    public function test_invalid_app_file_and_google_error_never_expose_credentials(): void
    {
        [$admin, $site] = $this->context();
        $json = $this->appJson();
        $json['web']['redirect_uris'] = ['https://attacker.test/callback'];
        $this->post(route('admin.sites.reporting.accounts.setup', $site), ['google_app' => UploadedFile::fake()->createWithContent('client.json', json_encode($json))])->assertSessionHasErrors('gam_account');
        $this->assertFalse(app(GamManagedCredentials::class)->hasApp());
        $this->tokenResponses();
        $this->google->error = 'Google error: private-refresh-token';
        $this->completeConsent($this->start($admin, $site)['state'])->assertSessionHasErrors('gam_account');
        $this->get(route('admin.sites.reporting.accounts.show', $site))->assertOk()->assertDontSee('private-refresh-token');
        $this->assertStringNotContainsString('private-refresh-token', AuditLog::all()->toJson());
        $this->assertDatabaseCount('gam_connections', 0);
    }
}
