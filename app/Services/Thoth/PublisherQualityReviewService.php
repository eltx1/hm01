<?php

namespace App\Services\Thoth;

use App\Enums\PublisherApplicationStatus;
use App\Models\AiProviderConnection;
use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherQualityProfile;
use App\Models\PublisherQualityReviewRun;
use App\Models\ThothSetting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Thoth\Data\PublisherQualityAiRequest;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PublisherQualityReviewService
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly PublisherEvidenceCollector $evidence,
        private readonly AuditRecorder $audit,
    ) {}

    public function run(Publisher $publisher, PublisherQualityProfile $profile, User $actor, bool $rerun = false): PublisherQualityReviewRun
    {
        $this->assertProfile($publisher, $profile);
        [$settings, $connection] = $this->readyProvider();
        $snapshot = $this->evidence->collectForPublisher($publisher, $profile);

        return $this->execute($publisher, $profile, $actor, $snapshot, $settings, $connection, $rerun);
    }

    public function runForApplication(PublisherApplication $application, PublisherQualityProfile $profile, User $actor, bool $rerun = false): PublisherQualityReviewRun
    {
        if (! in_array($application->status, [
            PublisherApplicationStatus::Submitted,
            PublisherApplicationStatus::UnderReview,
            PublisherApplicationStatus::MoreInfoRequired,
        ], true)) {
            throw ValidationException::withMessages(['thoth' => 'THOTH pre-approval review is not allowed in the current application state.']);
        }

        $publisher = Publisher::withoutGlobalScopes()->findOrFail($application->publisher_id);
        if ($publisher->organization_id !== $application->organization_id) {
            throw ValidationException::withMessages(['application' => 'The application does not belong to its Publisher organization.']);
        }
        $this->assertProfile($publisher, $profile);

        $this->audit->record(
            'application.thoth_review.requested',
            $application->organization_id,
            $actor,
            $application,
            metadata: ['application_status' => $application->status->value, 'profile_version' => $profile->version],
        );

        [$settings, $connection] = $this->readyProvider();
        $snapshot = $this->evidence->collectForApplication($application, $profile, $actor);
        $hasWebsiteEvidence = ($snapshot['website_evidence'] ?? []) !== [];
        $this->audit->record(
            $hasWebsiteEvidence ? 'application.thoth_evidence.collected' : 'application.thoth_evidence.unavailable',
            $application->organization_id,
            $actor,
            $application,
            metadata: [
                'application_status' => $application->status->value,
                'profile_version' => $profile->version,
                'website_authorization_verified' => (bool) ($snapshot['application']['website_authorization_verified'] ?? false),
                'verification_freshness' => $snapshot['application']['verification_freshness'] ?? 'UNKNOWN',
                'page_count' => count($snapshot['website_evidence'] ?? []),
                'gap_count' => count($snapshot['evidence_gaps'] ?? []),
            ],
        );

        return $this->execute($publisher, $profile, $actor, $snapshot, $settings, $connection, $rerun);
    }

    private function execute(
        Publisher $publisher,
        PublisherQualityProfile $profile,
        User $actor,
        array $snapshot,
        ThothSetting $settings,
        AiProviderConnection $connection,
        bool $rerun,
    ): PublisherQualityReviewRun {
        $evidenceHash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $key = hash('sha256', $publisher->id.'|'.$evidenceHash.'|'.$connection->provider.'|'.$connection->model.'|'.config('thoth.policy_version'));
        if (! $rerun && PublisherQualityReviewRun::query()
            ->where('publisher_id', $publisher->id)
            ->where('evidence_hash', $evidenceHash)
            ->where('provider', $connection->provider)
            ->where('model', $connection->model)
            ->where('policy_version', config('thoth.policy_version'))
            ->exists()) {
            throw ValidationException::withMessages(['thoth' => 'This evidence has already been reviewed. Select deliberate re-run to create a new run.']);
        }
        if (PublisherQualityReviewRun::query()->where('active_dedupe_key', $key)->exists()) {
            throw ValidationException::withMessages(['thoth' => 'An equivalent review is already running.']);
        }

        $run = PublisherQualityReviewRun::create([
            'publisher_id' => $publisher->id,
            'profile_id' => $profile->id,
            'requested_by' => $actor->id,
            'status' => 'RUNNING',
            'provider' => $connection->provider,
            'model' => $connection->model,
            'policy_version' => config('thoth.policy_version'),
            'schema_version' => config('thoth.schema_version'),
            'evidence_snapshot' => $snapshot,
            'evidence_hash' => $evidenceHash,
            'active_dedupe_key' => $key,
            'started_at' => now(),
        ]);
        $this->audit->record('thoth.review.started', $publisher->organization_id, $actor, $run, metadata: [
            'provider' => $run->provider,
            'model' => $run->model,
            'profile_version' => $profile->version,
            'review_context' => $snapshot['review_context'] ?? 'UNKNOWN',
        ]);

        try {
            $result = $this->providers->for($connection->provider)->analyze(
                new PublisherQualityAiRequest($snapshot, $run->policy_version, $run->schema_version, $run->id, $publisher->id),
                $connection->model,
                $connection->credential(),
                $settings->timeout_seconds,
                $settings->max_output_tokens,
            );
            $completedAt = now();
            $encodedResult = json_encode($result->result, JSON_THROW_ON_ERROR);
            $run->update([
                'status' => 'COMPLETED',
                'result' => $result->result,
                'response_hash' => hash('sha256', $encodedResult),
                'provider_request_id' => $result->requestId,
                'usage' => $result->usage,
                'latency_ms' => $run->started_at->diffInMilliseconds($completedAt),
                'completed_at' => $completedAt,
                'active_dedupe_key' => null,
            ]);
            $this->audit->record('thoth.review.completed', $publisher->organization_id, $actor, $run, metadata: [
                'recommendation' => $result->result['recommended_decision'],
                'risk_level' => $result->result['risk_level'],
                'review_context' => $snapshot['review_context'] ?? 'UNKNOWN',
            ]);
        } catch (Throwable $exception) {
            $code = $exception instanceof ThothProviderException ? $exception->safeCode : 'REVIEW_FAILED';
            $failedAt = now();
            $run->update([
                'status' => $code === 'TIMED_OUT' ? 'TIMED_OUT' : 'FAILED',
                'error_code' => $code,
                'latency_ms' => $run->started_at->diffInMilliseconds($failedAt),
                'completed_at' => $failedAt,
                'failed_at' => $failedAt,
                'active_dedupe_key' => null,
            ]);
            $this->audit->record('thoth.review.failed', $publisher->organization_id, $actor, $run, metadata: [
                'error_code' => $code,
                'review_context' => $snapshot['review_context'] ?? 'UNKNOWN',
            ]);
        }

        return $run->fresh();
    }

    /** @return array{0: ThothSetting, 1: AiProviderConnection} */
    private function readyProvider(): array
    {
        $settings = ThothSetting::current();
        if (! $settings->enabled) {
            throw ValidationException::withMessages(['thoth' => 'THOTH is disabled.']);
        }
        $connection = AiProviderConnection::query()->where('provider', $settings->active_provider)->first();
        if (! $connection?->credential() || ! $connection->isReady()) {
            throw ValidationException::withMessages(['thoth' => 'The active THOTH provider is not ready.']);
        }

        return [$settings, $connection];
    }

    private function assertProfile(Publisher $publisher, PublisherQualityProfile $profile): void
    {
        if ($profile->publisher_id !== $publisher->id) {
            throw ValidationException::withMessages(['profile' => 'The quality profile does not belong to this Publisher.']);
        }
    }
}
