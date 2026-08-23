<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Jobs\RunSiteQualityReview;
use App\Models\AiProviderConnection;
use App\Models\SiteQualityReviewRun;
use App\Models\SiteReview;
use App\Models\ThothSetting;
use App\Services\Sites\DnsResolver;
use App\Services\Sites\SiteLifecycleService;
use App\Services\Thoth\SiteQualityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AutomaticThothWebsiteReviewTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $publisherUser;

    private $publisher;

    private $site;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();

        $this->publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['business_domain' => 'publisher-owner.example']);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Review Site',
            'primary_domain' => 'review.example',
            'content_category' => 'NEWS',
        ]);
        $this->admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
    }

    public function test_publisher_submission_queues_automatic_review_without_changing_the_human_lifecycle(): void
    {
        Queue::fake();

        $this->actingAs($this->publisherUser)
            ->post(route('publisher.sites.submit', $this->site))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(SiteStatus::PendingReview, $this->site->fresh()->status);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $this->site->id, 'decision' => 'PENDING']);
        $this->assertDatabaseHas('site_quality_review_runs', [
            'site_id' => $this->site->id,
            'trigger' => 'AUTOMATIC',
            'status' => 'QUEUED',
        ]);
        Queue::assertPushed(RunSiteQualityReview::class, fn (RunSiteQualityReview $job) =>
            $job->runId === SiteQualityReviewRun::query()->where('site_id', $this->site->id)->value('id')
        );
    }

    public function test_even_thoth_dependency_resolution_failure_cannot_break_website_submission(): void
    {
        $this->app->bind(SiteQualityReviewService::class, fn () => throw new RuntimeException('simulated AI wiring failure'));

        $this->actingAs($this->publisherUser)
            ->post(route('publisher.sites.submit', $this->site))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(SiteStatus::PendingReview, $this->site->fresh()->status);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $this->site->id, 'decision' => 'PENDING']);
        $this->assertDatabaseMissing('site_quality_review_runs', ['site_id' => $this->site->id]);
    }

    public function test_disabled_ai_fails_safely_and_human_approval_controls_remain_available(): void
    {
        $this->pendingReview();
        Queue::fake();
        $run = app(SiteQualityReviewService::class)->queueAutomatic($this->site->fresh(), $this->publisherUser);

        $result = app(SiteQualityReviewService::class)->execute($run);

        $this->assertSame('FAILED', $result->status);
        $this->assertSame('THOTH_DISABLED', $result->error_code);
        $this->assertSame(SiteStatus::PendingReview, $this->site->fresh()->status);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $this->site->id, 'decision' => 'PENDING']);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $this->site))
            ->assertOk()
            ->assertSee('Quality Review')
            ->assertSee('THOTH advisory failed')
            ->assertSee('THOTH is disabled in the AI Control Center.')
            ->assertSee('Approve website')
            ->assertSee('Reject website');
    }

    public function test_provider_quota_failure_is_recorded_but_never_blocks_site_review(): void
    {
        $this->pendingReview();
        $this->readyOpenAi();
        $this->safePublicDns();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.openai.com/v1/responses')) {
                return Http::response(['error' => ['message' => 'quota exceeded raw provider detail']], 429);
            }

            return Http::response('<html><title>Review Site</title><h1>Original useful content</h1><p>Independent editorial website.</p></html>', 200, ['Content-Type' => 'text/html']);
        });
        Queue::fake();
        $run = app(SiteQualityReviewService::class)->queueAutomatic($this->site->fresh(), $this->publisherUser);

        $result = app(SiteQualityReviewService::class)->execute($run);

        $this->assertSame('FAILED', $result->status);
        $this->assertSame('RATE_LIMITED', $result->error_code);
        $this->assertSame('The AI provider rate limit or quota was reached.', $result->error_message);
        $this->assertStringNotContainsString('raw provider detail', json_encode($result->toArray()));
        $this->assertSame(SiteStatus::PendingReview, $this->site->fresh()->status);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $this->site->id, 'decision' => 'PENDING']);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $this->site))
            ->assertOk()
            ->assertSee('rate limit or quota was reached')
            ->assertSee('Approve website');
    }

    public function test_successful_site_advisory_is_visible_and_cannot_auto_approve_the_website(): void
    {
        $this->pendingReview();
        $this->readyOpenAi();
        $this->safePublicDns();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.openai.com/v1/responses')) {
                return Http::response($this->openAiResponse());
            }

            return Http::response('<html><title>Review Site</title><h1>Independent technology reporting</h1><p>Original articles and transparent contact information.</p></html>', 200, ['Content-Type' => 'text/html']);
        });
        Queue::fake();
        $run = app(SiteQualityReviewService::class)->queueAutomatic($this->site->fresh(), $this->publisherUser);

        $result = app(SiteQualityReviewService::class)->execute($run);

        $this->assertSame('COMPLETED', $result->status);
        $this->assertSame('APPROVE', $result->result['recommended_decision']);
        $this->assertSame('WEBSITE_REVIEW', $result->evidence_snapshot['review_context']);
        $this->assertSame($this->site->id, $result->evidence_snapshot['site']['id']);
        $this->assertFalse($result->evidence_snapshot['site']['production_activation_allowed_by_ai']);
        $this->assertSame(SiteStatus::PendingReview, $this->site->fresh()->status);
        $this->assertDatabaseHas('site_reviews', ['site_id' => $this->site->id, 'decision' => 'PENDING']);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $this->site))
            ->assertOk()
            ->assertSee('Quality Review')
            ->assertSee('Recommendation')
            ->assertSee('APPROVE')
            ->assertSee('Risk')
            ->assertSee('LOW')
            ->assertSee('Confidence')
            ->assertSee('92%')
            ->assertSee('Approve website')
            ->assertSee('Reject website');
    }

    public function test_admin_can_queue_a_manual_rerun_after_a_failed_advisory(): void
    {
        $this->pendingReview();
        SiteQualityReviewRun::create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'publisher_id' => $this->publisher->id,
            'requested_by' => $this->publisherUser->id,
            'trigger' => 'AUTOMATIC',
            'status' => 'FAILED',
            'policy_version' => config('thoth.policy_version'),
            'schema_version' => config('thoth.schema_version'),
            'error_code' => 'RATE_LIMITED',
            'error_message' => 'The AI provider rate limit or quota was reached.',
            'failed_at' => now(),
            'completed_at' => now(),
        ]);
        Queue::fake();

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.sites.quality-review', $this->site))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SiteQualityReviewRun::query()->where('site_id', $this->site->id)->count());
        $this->assertDatabaseHas('site_quality_review_runs', [
            'site_id' => $this->site->id,
            'trigger' => 'MANUAL',
            'status' => 'QUEUED',
        ]);
        $this->assertSame(SiteStatus::PendingReview, $this->site->fresh()->status);
        Queue::assertPushed(RunSiteQualityReview::class);
    }

    private function pendingReview(): void
    {
        app(SiteLifecycleService::class)->transition($this->site, SiteStatus::PendingReview, $this->publisherUser, 'Submitted by publisher.');
        SiteReview::create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'decision' => 'PENDING',
            'submitted_at' => now(),
        ]);
        $this->site->refresh();
    }

    private function readyOpenAi(): void
    {
        AiProviderConnection::query()->updateOrCreate(['provider' => 'OPENAI'], [
            'model' => 'gpt-5-mini',
            'encrypted_credential' => 'site-review-secret',
            'credential_source' => 'DATABASE',
            'status' => 'CONNECTED',
            'last_tested_at' => now(),
            'last_connected_at' => now(),
            'last_error_code' => null,
        ]);
        ThothSetting::current()->update([
            'enabled' => true,
            'active_provider' => 'OPENAI',
            'timeout_seconds' => 20,
            'max_output_tokens' => 1800,
        ]);
    }

    private function safePublicDns(): void
    {
        $dns = Mockery::mock(DnsResolver::class);
        $dns->shouldReceive('addresses')->with('review.example')->zeroOrMoreTimes()->andReturn(['93.184.216.34']);
        $this->app->instance(DnsResolver::class, $dns);
    }

    private function openAiResponse(): array
    {
        $result = [
            'recommended_decision' => 'APPROVE',
            'risk_level' => 'LOW',
            'confidence' => 92,
            'categories' => ['CONTENT_QUALITY', 'SITE_TRANSPARENCY'],
            'findings' => [[
                'code' => 'ORIGINAL_CONTENT',
                'severity' => 'LOW',
                'explanation' => 'The supplied public evidence shows substantive editorial content.',
                'evidence' => 'Homepage contains independent technology reporting.',
            ]],
            'positive_signals' => ['Substantive public content is visible.'],
            'concerns' => [],
            'recommended_admin_checks' => ['Confirm policy compliance before final approval.'],
            'summary' => 'The website appears suitable for monetization based on the supplied evidence.',
            'limitations' => ['THOTH is advisory only and did not inspect every page.'],
        ];

        return [
            'id' => 'resp_site_review_1',
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 80],
        ];
    }
}
