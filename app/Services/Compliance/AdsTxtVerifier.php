<?php

namespace App\Services\Compliance;

use App\Enums\AdsTxtComplianceStatus;
use App\Models\Site;
use App\Models\SupplyChainCheck;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\DomainNotificationService;
use Illuminate\Support\Facades\DB;

final class AdsTxtVerifier
{
    public function __construct(
        private readonly AdsTxtFetcher $fetcher,
        private readonly AdsTxtComparator $comparator,
        private readonly AdsTxtComplianceService $compliance,
        private readonly AuditRecorder $audit,
        private readonly DomainNotificationService $notifications,
    ) {}

    public function verify(Site $site, string $trigger = 'SCHEDULED', ?User $actor = null): SupplyChainCheck
    {
        $trigger = strtoupper($trigger);
        if (! in_array($trigger, ['SCHEDULED', 'ADMIN', 'PUBLISHER'], true)) {
            throw new \InvalidArgumentException('Unknown ads.txt verification trigger.');
        }

        $previousStatus = SupplyChainCheck::withoutGlobalScope('organization')
            ->where('site_id', $site->id)->where('check_type', 'ADS_TXT')
            ->latest('checked_at')->value('status');
        $canonical = $this->compliance->canonical($site);
        $fetch = $this->fetcher->fetch($site);
        $comparisonContent = (string) ($canonical['comparison_content'] ?? $canonical['content']);
        $comparison = $this->comparator->compare($comparisonContent, $fetch['ok'] ? $fetch['body'] : '', $canonical['findings']);
        $status = $comparison['status'];
        if (! $fetch['ok'] && ($canonical['required_record_count'] ?? $canonical['record_count']) > 0) {
            $status = in_array($fetch['error_code'], ['HTTP_404', 'HTTP_410'], true)
                ? AdsTxtComplianceStatus::Missing->value
                : AdsTxtComplianceStatus::Unreachable->value;
        }

        $findings = [
            'phase' => $canonical['phase'] ?? 'PRODUCTION',
            'fetch' => [
                'ok' => $fetch['ok'],
                'error_code' => $fetch['error_code'],
                'message' => $fetch['error'],
                'redirects' => $fetch['redirects'],
            ],
            'comparison' => $comparison,
            'canonical_findings' => $canonical['findings'],
        ];
        $checksum = $fetch['body'] !== '' ? hash('sha256', $fetch['body']) : null;
        $snapshotHash = hash('sha256', json_encode([
            'status' => $status,
            'required_checksum' => $canonical['checksum'],
            'checksum' => $checksum,
            'http_status' => $fetch['http_status'],
            'content_type' => $fetch['content_type'],
            'findings' => $findings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $checkedAt = now();

        $check = DB::transaction(function () use ($site, $actor, $trigger, $fetch, $findings, $status, $checksum, $canonical, $snapshotHash, $checkedAt): SupplyChainCheck {
            $latest = SupplyChainCheck::withoutGlobalScope('organization')
                ->where('site_id', $site->id)->where('check_type', 'ADS_TXT')
                ->latest('checked_at')->lockForUpdate()->first();
            $attributes = [
                'status' => $status,
                'url' => 'https://'.strtolower(rtrim($site->primary_domain, '.')).'/ads.txt',
                'final_url' => $fetch['final_url'],
                'http_status' => $fetch['http_status'],
                'checksum' => $checksum,
                'required_checksum' => $canonical['checksum'],
                'snapshot_hash' => $snapshotHash,
                'response_body' => $fetch['body'] !== '' ? $fetch['body'] : null,
                'response_bytes' => $fetch['bytes'],
                'duration_ms' => $fetch['duration_ms'],
                'content_type' => $fetch['content_type'],
                'trigger' => $trigger,
                'initiated_by' => $actor?->id,
                'findings' => $findings,
                'checked_at' => $checkedAt,
            ];
            if ($latest?->snapshot_hash === $snapshotHash) {
                $latest->update($attributes + ['occurrence_count' => $latest->occurrence_count + 1]);
                $check = $latest->refresh();
            } else {
                $check = SupplyChainCheck::withoutGlobalScopes()->create($attributes + [
                    'organization_id' => $site->organization_id,
                    'site_id' => $site->id,
                    'check_type' => 'ADS_TXT',
                    'first_checked_at' => $checkedAt,
                    'occurrence_count' => 1,
                ]);
            }

            $expired = SupplyChainCheck::withoutGlobalScope('organization')
                ->where('site_id', $site->id)->where('check_type', 'ADS_TXT')
                ->latest('checked_at')->skip(max(1, (int) config('ads-txt.history_snapshots', 30)))
                ->take(500)->pluck('id');
            if ($expired->isNotEmpty()) {
                SupplyChainCheck::withoutGlobalScope('organization')->whereKey($expired)->delete();
            }

            if ($actor && $trigger !== 'SCHEDULED') {
                $this->audit->record('supply_chain.ads_txt.manually_verified', $site->organization_id, $actor, $site, newValues: [
                    'check_id' => $check->id,
                    'status' => $check->status,
                    'trigger' => $trigger,
                ]);
            }

            return $check;
        });

        $this->notifications->adsTxtChanged($site, $check, $previousStatus);

        return $check;
    }
}
