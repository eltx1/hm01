<?php

namespace App\Services\Thoth;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Jobs\RunSiteQualityReview;
use App\Models\AiProviderConnection;
use App\Models\Site;
use App\Models\SiteQualityReviewRun;
use App\Models\ThothSetting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\HorusNotificationService;
use App\Services\Thoth\Data\PublisherQualityAiRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

final class SiteQualityReviewService
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly SiteEvidenceCollector $evidence,
        private readonly AuditRecorder $audit,
        private readonly HorusNotificationService $notifications,
    ) {}

    public function queueAutomatic(Site $site, ?User $actor = null): SiteQualityReviewRun
    {
        return $this->activeRun($site) ?? $this->queue($site, $actor, 'AUTOMATIC');
    }

    public function queueManual(Site $site, User $actor): SiteQualityReviewRun
    {
        return $this->activeRun($site) ?? $this->queue($site, $actor, 'MANUAL');
    }

    private function activeRun(Site $site): ?SiteQualityReviewRun
    {
        return SiteQualityReviewRun::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['QUEUED', 'RUNNING'])
            ->latest()
            ->first();
    }

    private function queue(Site $site, ?User $actor, string $trigger): SiteQualityReviewRun
    {
        $run = SiteQualityReviewRun::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'publisher_id' => $site->publisher_id,
            'requested_by' => $actor?->id,
            'trigger' => $trigger,
            'status' => 'QUEUED',
            'policy_version' => config('thoth.policy_version'),
            'schema_version' => config('thoth.schema_version'),
        ]);

        try {
            $this->audit->record('thoth.site_review.queued', $site->organization_id, $actor, $run, metadata: [
                'site_id' => $site->id,
                'trigger' => $trigger,
            ]);
        } catch (Throwable) {
            // Audit transport must never prevent the optional advisory from being queued.
        }

        try {
            RunSiteQualityReview::dispatch($run->id);
        } catch (Throwable) {
            if (! in_array($run->fresh()->status, ['COMPLETED', 'FAILED'], true)) {
                $this->fail($run->fresh(), 'QUEUE_UNAVAILABLE');
            }
        }

        return $run->fresh();
    }

    public function execute(SiteQualityReviewRun $run): SiteQualityReviewRun
    {
        $run = $run->fresh();
        if (in_array($run->status, ['COMPLETED', 'FAILED'], true)) {
            return $run;
        }

        $site = Site::withoutGlobalScopes()->with('publisher')->find($run->site_id);
        if (! $site) {
            return $this->fail($run, 'SITE_UNAVAILABLE');
        }

        $run->update(['status' => 'RUNNING', 'started_at' => now()]);
        $run = $run->fresh();

        try {
            $settings = ThothSetting::current();
            if (! $settings->enabled) {
                return $this->fail($run, 'THOTH_DISABLED');
            }

            $connection = AiProviderConnection::query()->where('provider', $settings->active_provider)->first();
            if (! $connection?->credential() || ! $connection->isReady()) {
                return $this->fail($run, 'PROVIDER_NOT_READY');
            }

            $run->update(['provider' => $connection->provider, 'model' => $connection->model]);
            $snapshot = $this->evidence->collect($site);
            $evidenceHash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $dedupeKey = hash('sha256', $site->id.'|'.$evidenceHash.'|'.$connection->provider.'|'.$connection->model.'|'.config('thoth.policy_version'));

            try {
                $run->update([
                    'evidence_snapshot' => $snapshot,
                    'evidence_hash' => $evidenceHash,
                    'active_dedupe_key' => $dedupeKey,
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->fail($run->fresh(), 'DUPLICATE_REVIEW');
            }

            $result = $this->providers->for($connection->provider)->analyze(
                new PublisherQualityAiRequest(
                    $snapshot,
                    $run->policy_version,
                    $run->schema_version,
                    $run->id,
                    $site->publisher_id,
                ),
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
                'latency_ms' => $run->started_at?->diffInMilliseconds($completedAt),
                'completed_at' => $completedAt,
                'active_dedupe_key' => null,
            ]);

            $run = $run->fresh();
            try {
                $this->audit->record('thoth.site_review.completed', $site->organization_id, $this->actor($run), $run, metadata: [
                    'site_id' => $site->id,
                    'recommendation' => $run->result['recommended_decision'] ?? null,
                    'risk_level' => $run->result['risk_level'] ?? null,
                ]);
            } catch (Throwable) {
                // The advisory result is already durable; audit delivery is secondary here.
            }
            $this->notifyAdmins($site, $run);

            return $run;
        } catch (ThothProviderException $exception) {
            return $this->fail($run->fresh(), $exception->safeCode);
        } catch (Throwable) {
            return $this->fail($run->fresh(), 'REVIEW_FAILED');
        }
    }

    private function fail(SiteQualityReviewRun $run, string $code): SiteQualityReviewRun
    {
        if (in_array($run->status, ['COMPLETED', 'FAILED'], true)) {
            return $run;
        }

        $failedAt = now();
        $run->update([
            'status' => 'FAILED',
            'error_code' => $code,
            'error_message' => $this->safeMessage($code),
            'latency_ms' => $run->started_at?->diffInMilliseconds($failedAt),
            'completed_at' => $failedAt,
            'failed_at' => $failedAt,
            'active_dedupe_key' => null,
        ]);

        $run = $run->fresh();
        $site = Site::withoutGlobalScopes()->find($run->site_id);
        if ($site) {
            try {
                $this->audit->record('thoth.site_review.failed', $site->organization_id, $this->actor($run), $run, metadata: [
                    'site_id' => $site->id,
                    'error_code' => $code,
                ]);
            } catch (Throwable) {
                // Failure visibility lives on the immutable run even if audit transport fails.
            }
            $this->notifyAdmins($site, $run);
        }

        return $run;
    }

    private function notifyAdmins(Site $site, SiteQualityReviewRun $run): void
    {
        try {
            $completed = $run->status === 'COMPLETED';
            $recommendation = $run->result['recommended_decision'] ?? 'No recommendation';
            $risk = $run->result['risk_level'] ?? 'unknown';
            $this->notifications->notify($this->notifications->horusRecipients('sites.review'), [
                'category' => NotificationCategory::Sites,
                'type' => $completed ? 'THOTH_SITE_REVIEW_COMPLETED' : 'THOTH_SITE_REVIEW_FAILED',
                'severity' => $completed ? NotificationSeverity::Info : NotificationSeverity::Warning,
                'title' => $completed ? 'THOTH website review completed' : 'THOTH website review failed',
                'message' => $completed
                    ? $site->display_name.': '.$recommendation.' · risk '.$risk.'. Human review remains required.'
                    : $site->display_name.': '.$run->error_message.' Human review can continue normally.',
                'event_key' => 'thoth-site-review-'.$run->id.'-'.$run->status,
                'related_type' => Site::class,
                'related_id' => $site->id,
                'action_route' => 'admin.sites.show',
                'action_parameters' => ['site' => $site->id],
            ]);
        } catch (Throwable) {
            // Notification delivery is secondary and must never affect Site lifecycle or AI persistence.
        }
    }

    private function actor(SiteQualityReviewRun $run): ?User
    {
        return $run->requested_by ? User::query()->find($run->requested_by) : null;
    }

    private function safeMessage(string $code): string
    {
        return match ($code) {
            'THOTH_DISABLED' => 'THOTH is disabled in the AI Control Center.',
            'PROVIDER_NOT_READY' => 'The active THOTH provider is not configured or has not passed a recent connection test.',
            'RATE_LIMITED' => 'The AI provider rate limit or quota was reached.',
            'AUTHENTICATION_FAILED' => 'The AI provider authentication failed.',
            'MODEL_UNAVAILABLE', 'MODEL_INCOMPATIBLE' => 'The configured AI model is unavailable or incompatible.',
            'TIMED_OUT' => 'The AI provider timed out.',
            'PROVIDER_UNAVAILABLE', 'PROVIDER_UNREACHABLE' => 'The AI provider is temporarily unavailable or unreachable.',
            'INVALID_RESPONSE' => 'The AI provider returned an invalid structured response.',
            'RESPONSE_TOO_LARGE' => 'The AI provider response exceeded the safe size limit.',
            'PROVIDER_REJECTED' => 'The AI provider rejected the review request.',
            'DUPLICATE_REVIEW' => 'An equivalent THOTH review is already running.',
            'QUEUE_UNAVAILABLE' => 'The background AI review could not be queued. Human website review is unaffected.',
            'SITE_UNAVAILABLE' => 'The website record was unavailable when the background review started.',
            default => 'THOTH could not complete the website advisory. Human website review is unaffected.',
        };
    }
}
