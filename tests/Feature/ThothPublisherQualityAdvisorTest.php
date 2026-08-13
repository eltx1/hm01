<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\AiProviderConnection;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\PublisherQualityDecision;
use App\Models\PublisherQualityProfile;
use App\Models\PublisherQualityReviewRun;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\ThothSetting;
use App\Services\Sites\DnsResolver;
use App\Services\Sites\DomainSafetyValidator;
use App\Services\Thoth\PublisherEvidenceCollector;
use App\Services\Thoth\PublisherQualityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class ThothPublisherQualityAdvisorTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private $admin;

    private Publisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $this->publisher = Publisher::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'legal_name' => 'Quality Publisher LLC', 'display_name' => 'Quality Publisher', 'business_domain' => 'publisher.test', 'status' => 'PENDING', 'onboarding_submitted_at' => now()]);
    }

    public function test_thoth_is_disabled_by_default_and_openai_is_the_default_provider(): void
    {
        $settings = ThothSetting::current();
        $this->assertFalse($settings->enabled);
        $this->assertSame('OPENAI', $settings->active_provider);
        $this->assertSame('gpt-5-mini', config('thoth.default_models.OPENAI'));
    }

    public function test_settings_are_horus_only_and_publisher_users_cannot_access_them_or_run_ai(): void
    {
        $publisherUser = $this->makeUser($this->publisher->organization, RoleName::PublisherAdmin);
        $this->actingAs($publisherUser)->get(route('admin.thoth.settings'))->assertForbidden();
        $this->post(route('admin.publishers.quality-review.run', $this->publisher))->assertForbidden();
    }

    public function test_credential_is_encrypted_hidden_and_never_rendered(): void
    {
        $session = ['two_factor_passed_at' => now()->timestamp];
        $this->actingAs($this->admin)->withSession($session)->put(route('admin.thoth.credentials.update', 'OPENAI'), ['credential' => 'sk-production-secret'])->assertRedirect();
        $connection = AiProviderConnection::firstOrFail();
        $raw = $connection->getRawOriginal('encrypted_credential');
        $this->assertNotSame('sk-production-secret', $raw);
        $this->assertSame('sk-production-secret', Crypt::decryptString($raw));
        $this->assertArrayNotHasKey('encrypted_credential', $connection->toArray());
        $this->get(route('admin.thoth.settings'))->assertDontSee('sk-production-secret')->assertSee('Configured · hidden');
        $this->assertDatabaseMissing('audit_logs', ['new_values' => '%sk-production-secret%']);
    }

    public function test_activation_is_rejected_until_selected_provider_has_recent_successful_test(): void
    {
        AiProviderConnection::create(['provider' => 'OPENAI', 'model' => 'gpt-5-mini', 'encrypted_credential' => 'secret-key', 'credential_source' => 'DATABASE', 'status' => 'UNTESTED']);
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])->put(route('admin.thoth.settings.update'), ['enabled' => 1, 'active_provider' => 'OPENAI', 'timeout_seconds' => 20, 'max_output_tokens' => 1800])->assertSessionHasErrors('enabled');
        $this->assertFalse(ThothSetting::current()->fresh()->enabled);
    }

    public function test_environment_credential_is_used_without_copying_it_to_database_and_admin_secret_can_be_removed(): void
    {
        Config::set('thoth.credentials.OPENAI', 'server-only-secret');
        $connection = AiProviderConnection::create(['provider' => 'OPENAI', 'model' => 'gpt-5-mini', 'credential_source' => 'ENVIRONMENT', 'status' => 'UNTESTED']);
        $this->assertSame('server-only-secret', $connection->credential());
        $this->assertNull($connection->getRawOriginal('encrypted_credential'));
        $connection->update(['encrypted_credential' => 'admin-secret', 'credential_source' => 'DATABASE']);
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])->delete(route('admin.thoth.credentials.destroy', 'OPENAI'))->assertRedirect();
        $this->assertNull($connection->fresh()->getRawOriginal('encrypted_credential'));
        $this->assertSame('server-only-secret', $connection->fresh()->credential());
        $audit = AuditLog::query()->where('event', 'thoth.credential.removed')->firstOrFail();
        $this->assertStringNotContainsString('server-only-secret', json_encode($audit->toArray()));
        $this->assertStringNotContainsString('admin-secret', json_encode($audit->toArray()));
    }

    public function test_real_connection_test_validates_model_and_then_allows_activation(): void
    {
        $connection = AiProviderConnection::create(['provider' => 'OPENAI', 'model' => 'gpt-5-mini', 'encrypted_credential' => 'secret-key', 'credential_source' => 'DATABASE', 'status' => 'UNTESTED']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse())]);
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.thoth.connections.test', 'OPENAI'))->assertSessionHas('status');
        $this->assertTrue($connection->fresh()->isReady());
        $this->put(route('admin.thoth.settings.update'), ['enabled' => 1, 'active_provider' => 'OPENAI', 'timeout_seconds' => 20, 'max_output_tokens' => 1800])->assertSessionHasNoErrors();
        $this->assertTrue(ThothSetting::current()->fresh()->enabled);
    }

    public function test_profile_requires_traffic_and_device_totals_of_one_hundred(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $bad = $this->profilePayload();
        $bad['direct_percent'] = 99;
        $this->post(route('admin.publishers.quality-profile', $this->publisher), $bad)->assertSessionHasErrors('direct_percent');
        $bad = $this->profilePayload();
        $bad['desktop_percent'] = 99;
        $this->post(route('admin.publishers.quality-profile', $this->publisher), $bad)->assertSessionHasErrors('desktop_percent');
    }

    public function test_profile_saves_append_only_versions_with_complete_declarations(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->post(route('admin.publishers.quality-profile', $this->publisher), $this->profilePayload())->assertSessionHasNoErrors();
        $this->post(route('admin.publishers.quality-profile', $this->publisher), array_merge($this->profilePayload(), ['content_description' => 'Second review evidence.']))->assertSessionHasNoErrors();
        $this->assertSame([1, 2], PublisherQualityProfile::orderBy('version')->pluck('version')->all());
        $this->assertTrue(PublisherQualityProfile::latest('version')->first()->declarations['original_content']);
    }

    public function test_ai_run_is_advisory_only_preserves_state_and_records_canonical_metadata(): void
    {
        $profile = $this->profile();
        $this->readyConnection();
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse())]);
        $before = $this->publisher->status->value;
        $run = app(PublisherQualityReviewService::class)->run($this->publisher, $profile, $this->admin);
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame($before, $this->publisher->fresh()->status->value);
        $this->assertSame('gpt-5-mini', $run->model);
        $this->assertSame(config('thoth.policy_version'), $run->policy_version);
        $this->assertSame(64, strlen($run->evidence_hash));
        $this->assertArrayNotHasKey('internal_notes', $run->evidence_snapshot['publisher']);
        $this->assertArrayNotHasKey('paymentProfile', $run->evidence_snapshot);
    }

    public function test_failed_run_is_visible_safe_and_does_not_change_publisher(): void
    {
        $profile = $this->profile();
        $this->readyConnection();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'raw provider secret']], 500)]);
        $run = app(PublisherQualityReviewService::class)->run($this->publisher, $profile, $this->admin);
        $this->assertSame('FAILED', $run->status);
        $this->assertSame('PROVIDER_UNAVAILABLE', $run->error_code);
        $this->assertNull($run->result);
        $this->assertSame('PENDING', $this->publisher->fresh()->status->value);
    }

    public function test_human_decision_is_separate_immutable_and_authoritative_with_or_without_ai(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.publishers.review', $this->publisher), ['decision' => 'APPROVE', 'reason' => 'Human verified all evidence.'])->assertRedirect();
        $decision = PublisherQualityDecision::firstOrFail();
        $this->assertSame('ACTIVE', $this->publisher->fresh()->status->value);
        $this->assertNull($decision->review_run_id);
        $this->expectException(LogicException::class);
        $decision->update(['reason' => 'rewrite']);
    }

    public function test_completed_review_run_is_immutable(): void
    {
        $run = PublisherQualityReviewRun::create(['publisher_id' => $this->publisher->id, 'profile_id' => $this->profile()->id, 'requested_by' => $this->admin->id, 'status' => 'COMPLETED', 'provider' => 'OPENAI', 'model' => 'gpt-5-mini', 'policy_version' => 'v1', 'schema_version' => '1', 'evidence_snapshot' => [], 'evidence_hash' => hash('sha256', 'x'), 'result' => $this->advisoryResult(), 'completed_at' => now()]);
        $this->expectException(LogicException::class);
        $run->update(['error_code' => 'rewrite']);
    }

    public function test_publisher_360_shows_ai_advisory_and_human_authority_language(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get(route('admin.publishers.show', $this->publisher))->assertOk()->assertSee('Publisher Quality Review')->assertSee('advisory evidence only')->assertSee('Human final decision');
    }

    public function test_master_switch_prevents_every_external_ai_call_while_manual_review_still_works(): void
    {
        Http::fake();
        try {
            app(PublisherQualityReviewService::class)->run($this->publisher, $this->profile(), $this->admin);
            $this->fail('Expected disabled THOTH validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('thoth', $exception->errors());
        }
        Http::assertNothingSent();
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.publishers.review', $this->publisher), ['decision' => 'APPROVE', 'reason' => 'Completed entirely manual review.'])->assertRedirect();
        $this->assertSame('ACTIVE', $this->publisher->fresh()->status->value);
    }

    public function test_duplicate_same_evidence_requires_deliberate_rerun_and_rerun_is_append_only(): void
    {
        $profile = $this->profile();
        $this->readyConnection();
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse())]);
        $service = app(PublisherQualityReviewService::class);
        $first = $service->run($this->publisher, $profile, $this->admin);
        try {
            $service->run($this->publisher, $profile, $this->admin);
            $this->fail('Expected duplicate review validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('thoth', $exception->errors());
        }
        $second = $service->run($this->publisher, $profile, $this->admin, true);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->evidence_hash, $second->evidence_hash);
        $this->assertDatabaseCount('publisher_quality_review_runs', 2);
    }

    public function test_explicit_provider_switch_affects_future_run_and_preserves_historical_metadata(): void
    {
        $profile = $this->profile();
        $this->readyConnection();
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse()), 'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->advisoryResult())]]]]]])]);
        $first = app(PublisherQualityReviewService::class)->run($this->publisher, $profile, $this->admin);
        AiProviderConnection::create(['provider' => 'GEMINI', 'model' => 'gemini-2.5-flash', 'encrypted_credential' => 'gem-secret-key', 'credential_source' => 'DATABASE', 'status' => 'CONNECTED', 'last_tested_at' => now(), 'last_connected_at' => now()]);
        ThothSetting::current()->update(['active_provider' => 'GEMINI']);
        $second = app(PublisherQualityReviewService::class)->run($this->publisher, $profile, $this->admin);
        $this->assertSame(['OPENAI', 'gpt-5-mini'], [$first->fresh()->provider, $first->fresh()->model]);
        $this->assertSame(['GEMINI', 'gemini-2.5-flash'], [$second->provider, $second->model]);
        $this->assertSame(config('thoth.policy_version'), $first->fresh()->policy_version);
    }

    public function test_only_verified_domains_are_fetched_and_scripts_are_never_executed_or_retained(): void
    {
        $site = Site::create(['organization_id' => $this->publisher->organization_id, 'publisher_id' => $this->publisher->id, 'display_name' => 'Verified Site', 'primary_domain' => 'verified.example', 'content_category' => 'NEWS', 'country' => 'US', 'default_revenue_share_percent' => 70]);
        foreach ([['verified.example', 'VERIFIED'], ['unverified.example', 'PENDING']] as [$domain, $status]) {
            SiteDomain::create(['organization_id' => $this->publisher->organization_id, 'site_id' => $site->id, 'domain' => $domain, 'verification_status' => $status, 'verification_token' => str_repeat('a', 32)]);
        }
        $dns = Mockery::mock(DnsResolver::class);
        $dns->shouldReceive('addresses')->with('verified.example')->andReturn(['93.184.216.34']);
        Http::fake(['https://verified.example/*' => Http::response('<html><title>News</title><script>approvePublisher()</script><h1>Independent news</h1></html>', 200, ['content-type' => 'text/html']), '*' => Http::response('', 404)]);
        $snapshot = (new PublisherEvidenceCollector(new DomainSafetyValidator($dns)))->collect($this->publisher, $this->profile());
        $this->assertCount(4, $snapshot['website_evidence']);
        $this->assertStringContainsString('Independent news', $snapshot['website_evidence'][0]['visible_text']);
        $this->assertStringNotContainsString('approvePublisher', $snapshot['website_evidence'][0]['visible_text']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'unverified.example'));
    }

    public function test_private_or_loopback_verified_domain_is_blocked_before_http_fetch(): void
    {
        $site = Site::create(['organization_id' => $this->publisher->organization_id, 'publisher_id' => $this->publisher->id, 'display_name' => 'Unsafe Site', 'primary_domain' => 'unsafe.example', 'content_category' => 'NEWS', 'country' => 'US', 'default_revenue_share_percent' => 70]);
        SiteDomain::create(['organization_id' => $this->publisher->organization_id, 'site_id' => $site->id, 'domain' => 'unsafe.example', 'verification_status' => 'VERIFIED', 'verification_token' => str_repeat('b', 32)]);
        $dns = Mockery::mock(DnsResolver::class);
        $dns->shouldReceive('addresses')->with('unsafe.example')->andReturn(['127.0.0.1']);
        Http::fake();
        $snapshot = (new PublisherEvidenceCollector(new DomainSafetyValidator($dns)))->collect($this->publisher, $this->profile());
        Http::assertNothingSent();
        $this->assertSame([], $snapshot['website_evidence']);
        $this->assertNotEmpty($snapshot['evidence_gaps']);
    }

    private function profile(): PublisherQualityProfile
    {
        return PublisherQualityProfile::create(['publisher_id' => $this->publisher->id, 'version' => 1, 'content_categories' => ['NEWS'], 'content_description' => 'Original reporting.', 'traffic_profile' => ['monthly_pageviews' => 100000, 'organic' => 0, 'social' => 0, 'direct' => 100, 'paid' => 0, 'other' => 0], 'audience_countries' => ['US'], 'device_mix' => ['desktop' => 100, 'mobile' => 0, 'tablet' => 0], 'declarations' => ['original_content' => true], 'created_by' => $this->admin->id]);
    }

    private function readyConnection(): void
    {
        AiProviderConnection::create(['provider' => 'OPENAI', 'model' => 'gpt-5-mini', 'encrypted_credential' => 'secret-key', 'credential_source' => 'DATABASE', 'status' => 'CONNECTED', 'last_tested_at' => now(), 'last_connected_at' => now()]);
        ThothSetting::current()->update(['enabled' => true]);
    }

    private function advisoryResult(): array
    {
        return ['recommended_decision' => 'REVIEW_REQUIRED', 'risk_level' => 'MEDIUM', 'confidence' => 75, 'categories' => ['CONTENT_QUALITY'], 'summary' => 'Review manually.', 'findings' => [['code' => 'GAP', 'severity' => 'MEDIUM', 'explanation' => 'Evidence gap.', 'evidence' => 'No verified website.']], 'positive_signals' => [], 'concerns' => ['Evidence gap.'], 'recommended_admin_checks' => ['Verify it.'], 'limitations' => ['Static evidence only.']];
    }

    private function openAiResponse(): array
    {
        return ['id' => 'resp_test', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($this->advisoryResult())]]]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 100]];
    }

    private function profilePayload(): array
    {
        return ['content_categories' => ['NEWS'], 'content_description' => 'Original reporting.', 'monthly_pageviews' => 100000, 'organic_percent' => 0, 'social_percent' => 0, 'direct_percent' => 100, 'paid_percent' => 0, 'other_percent' => 0, 'audience_countries' => ['US'], 'desktop_percent' => 100, 'mobile_percent' => 0, 'tablet_percent' => 0, 'original_content' => 1, 'has_privacy_policy' => 1, 'has_contact_details' => 1, 'review_comments' => 'Manual evidence checked.'];
    }
}
