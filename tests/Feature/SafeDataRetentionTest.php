<?php

namespace Tests\Feature;

use App\Models\PublisherApplicationLegalAcceptance;
use App\Models\PublisherApplicationRevision;
use App\Models\PublisherQualityProfile;
use App\Models\Site;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class SafeDataRetentionTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();

        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('supply-chain.manager_domain', 'horusmedia.net');
        Config::set('data-retention.chunk_size', 2);
        Config::set('data-retention.datasets.synthetic_probe_results.retention_days', 90);
        Config::set('data-retention.datasets.privacy_diagnostic_evidence.retention_days', 365);
        Config::set('data-retention.datasets.privacy_diagnostic_tokens.expired_grace_days', 30);
        Config::set('data-retention.datasets.expired_user_invitations.retention_days', 180);
        Config::set('data-retention.datasets.completed_job_batches.retention_days', 90);
    }

    public function test_recent_transient_data_survives_and_expired_eligible_data_is_pruned_in_chunks(): void
    {
        [$application] = $this->application('retention-a.example', 'owner-a@retention.example');
        [$otherApplication] = $this->application('retention-b.example', 'owner-b@retention.example');
        $recentId = (string) Str::ulid();
        $otherRecentId = (string) Str::ulid();

        $this->insertProbe($recentId, $application->organization_id, now()->subDays(10));
        $this->insertProbe($otherRecentId, $otherApplication->organization_id, now()->subDays(20));

        $expired = [];
        for ($i = 0; $i < 5; $i++) {
            $id = (string) Str::ulid();
            $expired[] = $id;
            $this->insertProbe(
                $id,
                $i % 2 === 0 ? $application->organization_id : $otherApplication->organization_id,
                now()->subDays(120 + $i),
            );
        }

        $this->artisan('data-retention:prune', ['--execute' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('synthetic_probe_results', ['id' => $recentId]);
        $this->assertDatabaseHas('synthetic_probe_results', ['id' => $otherRecentId]);
        foreach ($expired as $id) {
            $this->assertDatabaseMissing('synthetic_probe_results', ['id' => $id]);
        }
        $this->assertDatabaseCount('synthetic_probe_results', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.data_retention_pruned']);
    }

    public function test_other_ephemeral_rules_preserve_references_and_terminal_state_boundaries(): void
    {
        [$application, $user] = $this->application('ephemeral.example', 'ephemeral@retention.example');
        $site = Site::withoutGlobalScopes()->create([
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'display_name' => 'Retention Site',
            'primary_domain' => 'ephemeral.example',
            'language' => 'en',
            'content_category' => 'general',
            'country' => 'US',
            'default_revenue_share_percent' => 70.00,
        ]);

        $expiredTokenId = (string) Str::ulid();
        DB::table('privacy_diagnostic_tokens')->insert([
            'id' => $expiredTokenId,
            'site_id' => $site->id,
            'environment' => 'PRODUCTION',
            'token_hash' => hash('sha256', 'expired-retention-token'),
            'allowed_hostnames' => json_encode(['ephemeral.example'], JSON_THROW_ON_ERROR),
            'max_reports' => 1,
            'report_count' => 1,
            'created_by' => $user->id,
            'expires_at' => now()->subDays(60),
            'completed_at' => now()->subDays(60),
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);

        $recentEvidenceId = (string) Str::ulid();
        $this->insertPrivacyEvidence(
            $recentEvidenceId,
            $application->organization_id,
            $site->id,
            $expiredTokenId,
            now()->subDays(10),
        );

        $recentTokenId = (string) Str::ulid();
        DB::table('privacy_diagnostic_tokens')->insert([
            'id' => $recentTokenId,
            'site_id' => $site->id,
            'environment' => 'PRODUCTION',
            'token_hash' => hash('sha256', 'recent-retention-token'),
            'allowed_hostnames' => json_encode(['ephemeral.example'], JSON_THROW_ON_ERROR),
            'max_reports' => 1,
            'report_count' => 1,
            'created_by' => $user->id,
            'expires_at' => now()->addDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $oldEvidenceId = (string) Str::ulid();
        $this->insertPrivacyEvidence(
            $oldEvidenceId,
            $application->organization_id,
            $site->id,
            $recentTokenId,
            now()->subDays(400),
        );

        $expiredInvitationId = (string) Str::ulid();
        DB::table('user_invitations')->insert([
            'id' => $expiredInvitationId,
            'organization_id' => $application->organization_id,
            'role_id' => null,
            'invited_by' => $user->id,
            'email' => 'never-accepted@retention.example',
            'token_hash' => hash('sha256', 'never-accepted-invitation'),
            'expires_at' => now()->subDays(200),
            'accepted_at' => null,
            'created_at' => now()->subDays(210),
            'updated_at' => now()->subDays(200),
        ]);

        $acceptedInvitationId = (string) Str::ulid();
        DB::table('user_invitations')->insert([
            'id' => $acceptedInvitationId,
            'organization_id' => $application->organization_id,
            'role_id' => null,
            'invited_by' => $user->id,
            'email' => 'accepted@retention.example',
            'token_hash' => hash('sha256', 'accepted-invitation'),
            'expires_at' => now()->subDays(200),
            'accepted_at' => now()->subDays(205),
            'created_at' => now()->subDays(210),
            'updated_at' => now()->subDays(205),
        ]);

        $terminalBatchId = 'retention-terminal-batch';
        DB::table('job_batches')->insert([
            'id' => $terminalBatchId,
            'name' => 'Retention terminal batch',
            'total_jobs' => 1,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => now()->subDays(100)->timestamp,
            'finished_at' => now()->subDays(99)->timestamp,
        ]);

        $pendingBatchId = 'retention-pending-batch';
        DB::table('job_batches')->insert([
            'id' => $pendingBatchId,
            'name' => 'Retention pending batch',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => now()->subDays(100)->timestamp,
            'finished_at' => null,
        ]);

        $this->artisan('data-retention:prune', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseMissing('privacy_diagnostic_tokens', ['id' => $expiredTokenId]);
        $this->assertDatabaseHas('privacy_diagnostic_evidence', [
            'id' => $recentEvidenceId,
            'privacy_diagnostic_token_id' => null,
        ]);
        $this->assertDatabaseHas('privacy_diagnostic_tokens', ['id' => $recentTokenId]);
        $this->assertDatabaseMissing('privacy_diagnostic_evidence', ['id' => $oldEvidenceId]);
        $this->assertDatabaseMissing('user_invitations', ['id' => $expiredInvitationId]);
        $this->assertDatabaseHas('user_invitations', ['id' => $acceptedInvitationId]);
        $this->assertDatabaseMissing('job_batches', ['id' => $terminalBatchId]);
        $this->assertDatabaseHas('job_batches', ['id' => $pendingBatchId]);
    }

    public function test_dry_run_deletes_nothing_and_execution_is_idempotent(): void
    {
        [$application] = $this->application('dry-run.example', 'dry-run@retention.example');
        $expiredId = (string) Str::ulid();
        $this->insertProbe($expiredId, $application->organization_id, now()->subDays(200));

        $this->artisan('data-retention:prune')->assertSuccessful();
        $this->assertDatabaseHas('synthetic_probe_results', ['id' => $expiredId]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.data_retention_previewed']);

        $this->artisan('data-retention:prune', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('synthetic_probe_results', ['id' => $expiredId]);

        $auditCountBefore = DB::table('audit_logs')->where('event', 'operations.data_retention_pruned')->count();
        $this->artisan('data-retention:prune', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('synthetic_probe_results', ['id' => $expiredId]);
        $this->assertSame(
            $auditCountBefore + 1,
            DB::table('audit_logs')->where('event', 'operations.data_retention_pruned')->count(),
        );
    }

    public function test_dataset_failure_is_isolated_and_operation_summary_is_still_audited(): void
    {
        [$application] = $this->application('failure-isolation.example', 'failure-isolation@retention.example');
        $expiredId = (string) Str::ulid();
        $this->insertProbe($expiredId, $application->organization_id, now()->subDays(200));

        $datasets = config('data-retention.datasets');
        $datasets['unsupported_future_dataset'] = [
            'table' => 'unsupported_future_dataset',
            'category' => 'EPHEMERAL',
            'retention_days' => 1,
        ];
        Config::set('data-retention.datasets', $datasets);

        $this->artisan('data-retention:prune', ['--execute' => true])->assertExitCode(1);

        $this->assertDatabaseMissing('synthetic_probe_results', ['id' => $expiredId]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.data_retention_pruned']);
    }

    public function test_permanent_identity_application_legal_contract_finance_and_payout_history_survives_forever(): void
    {
        [$application, $user] = $this->application('history.example', 'history@retention.example');
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        $old = now()->subYears(20);

        DB::table('seller_declarations')
            ->whereIn('id', [$reserved['publisher_seller']->id, $reserved['website_seller']->id])
            ->update(['created_at' => $old, 'updated_at' => $old]);
        DB::table('publisher_applications')->where('id', $application->id)
            ->update(['created_at' => $old, 'updated_at' => $old]);

        $profile = PublisherQualityProfile::create([
            'publisher_id' => $application->publisher_id,
            'version' => 1,
            'content_categories' => ['general'],
            'content_description' => 'Historical retention contract fixture.',
            'traffic_profile' => ['monthly_pageviews' => 1000],
            'audience_countries' => ['US'],
            'device_mix' => ['desktop' => 100],
            'declarations' => ['original_content' => true],
            'created_by' => $user->id,
        ]);

        $revision = PublisherApplicationRevision::create([
            'publisher_application_id' => $application->id,
            'publisher_quality_profile_id' => $profile->id,
            'submitted_by' => $user->id,
            'version' => 1,
            'snapshot' => ['publisher' => ['name' => 'Historical Publisher']],
            'snapshot_hash' => hash('sha256', 'historical-revision'),
            'submitted_at' => $old,
        ]);

        $legal = PublisherApplicationLegalAcceptance::create([
            'publisher_application_id' => $application->id,
            'user_id' => $user->id,
            'document_type' => 'TERMS_OF_SERVICE',
            'document_version' => 'historical-v1',
            'canonical_url' => 'https://horusmedia.net/legal/historical-v1',
            'accepted_at' => $old,
            'request_evidence_hash' => hash('sha256', 'request-evidence'),
            'evidence_hash' => hash('sha256', 'acceptance-evidence'),
        ]);

        DB::table('publisher_application_revisions')->where('id', $revision->id)->update(['created_at' => $old]);

        $contractId = (string) Str::ulid();
        DB::table('publisher_contracts')->insert([
            'id' => $contractId,
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'contract_reference' => 'RETENTION-HISTORY-1',
            'starts_at' => $old->toDateString(),
            'auto_renews' => false,
            'revenue_share_percent' => 70.00,
            'payment_threshold' => 100.00,
            'currency' => 'USD',
            'payment_terms' => 'NET30',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $periodId = (string) Str::ulid();
        DB::table('financial_periods')->insert([
            'id' => $periodId,
            'organization_id' => $application->organization_id,
            'period_key' => '2006-01',
            'starts_on' => '2006-01-01',
            'ends_on' => '2006-01-31',
            'currency' => 'USD',
            'status' => 'CLOSED',
            'closed_at' => $old,
            'closed_by' => $user->id,
            'snapshot_hash' => hash('sha256', 'closed-period'),
            'totals' => json_encode(['publisher_earnings_minor' => 10000], JSON_THROW_ON_ERROR),
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $statementId = (string) Str::ulid();
        DB::table('publisher_statements')->insert([
            'id' => $statementId,
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'financial_period_id' => $periodId,
            'statement_number' => 'RET-STMT-2006-01',
            'status' => 'FINALIZED',
            'currency' => 'USD',
            'publisher_earnings_minor' => 10000,
            'paid_minor' => 10000,
            'balance_due_minor' => 0,
            'line_items' => json_encode([], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode(['finalized' => true], JSON_THROW_ON_ERROR),
            'snapshot_hash' => hash('sha256', 'finalized-statement'),
            'finalized_at' => $old,
            'finalized_by' => $user->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $paymentId = (string) Str::ulid();
        DB::table('publisher_payments')->insert([
            'id' => $paymentId,
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'publisher_statement_id' => $statementId,
            'payment_number' => 'RET-PAY-2006-01',
            'status' => 'PAID',
            'currency' => 'USD',
            'amount_minor' => 10000,
            'settled_amount_minor' => 10000,
            'payment_method' => 'TEST_HISTORY',
            'horus_payment_reference' => 'RET-HISTORY-REF',
            'paid_at' => $old,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => $old,
            'processed_by' => $user->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $settlementId = (string) Str::ulid();
        DB::table('publisher_payment_settlements')->insert([
            'id' => $settlementId,
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'publisher_payment_id' => $paymentId,
            'settlement_reference' => 'RET-SETTLEMENT-2006-01',
            'amount_minor' => 10000,
            'currency' => 'USD',
            'settled_on' => $old,
            'recorded_by' => $user->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $this->artisan('data-retention:prune', ['--execute' => true])->assertSuccessful();

        foreach ([
            ['seller_declarations', $reserved['publisher_seller']->id],
            ['seller_declarations', $reserved['website_seller']->id],
            ['publisher_applications', $application->id],
            ['publisher_application_revisions', $revision->id],
            ['publisher_application_legal_acceptances', $legal->id],
            ['publisher_contracts', $contractId],
            ['financial_periods', $periodId],
            ['publisher_statements', $statementId],
            ['publisher_payments', $paymentId],
            ['publisher_payment_settlements', $settlementId],
        ] as [$table, $id]) {
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
    }

    public function test_scheduler_and_permanent_exclusion_contract_are_registered(): void
    {
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($consoleRoutes);
        $this->assertStringContainsString("data-retention:prune --execute", $consoleRoutes);
        $this->assertStringContainsString("dailyAt('00:40')->withoutOverlapping(180)", $consoleRoutes);

        $permanent = config('data-retention.permanent_business_tables');
        foreach ([
            'seller_declarations',
            'bidder_ads_txt_records',
            'platform_ads_txt_records',
            'publisher_application_revisions',
            'publisher_application_legal_acceptances',
            'publisher_contracts',
            'financial_periods',
            'publisher_statements',
            'publisher_payments',
            'publisher_payment_settlements',
            'publisher_quality_decisions',
            'global_settings',
        ] as $table) {
            $this->assertContains($table, $permanent);
            $this->assertArrayNotHasKey($table, config('data-retention.datasets'));
        }
    }

    /** @return array{0:\App\Models\PublisherApplication, 1:\App\Models\User} */
    private function application(string $domain, string $email): array
    {
        $application = app(PublisherApplicationService::class)->register([
            'name' => 'Retention Owner',
            'email' => $email,
            'publisher_name' => 'Retention Publisher '.Str::upper(Str::random(5)),
            'primary_domain' => $domain,
            'password' => 'Secure-Password-2026!',
        ]);
        $user = $application->applicant;
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$application->fresh(), $user->fresh()];
    }

    private function insertProbe(string $id, string $organizationId, mixed $observedAt): void
    {
        DB::table('synthetic_probe_results')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'site_id' => null,
            'probe' => 'RETENTION_TEST',
            'environment' => 'TEST',
            'status' => 'PASS',
            'latency_ms' => 1,
            'checks' => json_encode(['safe' => true], JSON_THROW_ON_ERROR),
            'release' => 'retention-test',
            'observed_at' => $observedAt,
            'created_at' => $observedAt,
            'updated_at' => $observedAt,
        ]);
    }

    private function insertPrivacyEvidence(
        string $id,
        string $organizationId,
        string $siteId,
        ?string $tokenId,
        mixed $observedAt,
    ): void {
        DB::table('privacy_diagnostic_evidence')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'site_id' => $siteId,
            'privacy_diagnostic_token_id' => $tokenId,
            'environment' => 'PRODUCTION',
            'result_status' => 'PASS',
            'loader_version' => 'retention-test',
            'config_version' => 1,
            'hostname' => 'ephemeral.example',
            'tcf_api_detected' => true,
            'tcf_api_responded' => true,
            'gpp_api_detected' => false,
            'gpp_api_responded' => false,
            'gpc_detected' => false,
            'configured_timeout_action' => 'NO_ADS',
            'prebid_consent_configured' => true,
            'prebid_storage_control_configured' => true,
            'prebid_activity_controls_configured' => true,
            'privacy_gate_respected' => true,
            'observed_at' => $observedAt,
            'result_hash' => hash('sha256', $id),
            'created_at' => $observedAt,
            'updated_at' => $observedAt,
        ]);
    }
}
