<?php

namespace App\Services\Compliance;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SupplyChainCheck;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\Sites\SiteAdsTxtInstallationService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainStandardsContract;

final class AdsTxtComplianceService
{
    public function __construct(
        private readonly SupplyChainArtifactBuilder $artifacts,
        private readonly SupplyChainStandardsContract $contract,
        private readonly AdsTxtParser $parser,
        private readonly AdsTxtComparator $comparator,
        private readonly BidderAdsTxtService $bidderAdsTxt,
        private readonly SiteAdsTxtInstallationService $installation,
    ) {}

    /** @return array<string, mixed> */
    public function canonical(Site $site): array
    {
        $status = $site->status instanceof SiteStatus ? $site->status : SiteStatus::tryFrom((string) $site->status);
        if ($status !== SiteStatus::Active) {
            $bundle = $this->installation->bundle($site);
            if ($bundle['available']) {
                return $this->activationCanonical($bundle);
            }
        }

        return $this->productionCanonical($site);
    }

    /** @return array<string, mixed> */
    private function productionCanonical(Site $site): array
    {
        $result = $this->contract->adsTxtForSite($site);
        $content = $this->artifacts->adsTxtForSite($site, $result);
        $entries = $result['entries'] ?? [];
        $records = collect($entries)->map(function (array $entry): array {
            $sourceType = $entry['source_type'] ?? 'DEMAND_RECORD';
            $provenance = $entry['provenance'] ?? [];
            if ($sourceType === 'SELLER_DECLARATION') {
                $declaration = $entry['declaration'];
                return [
                    'id' => $declaration->id,
                    'canonical' => $entry['line'],
                    'source' => 'CANONICAL_SELLER_IDENTITY',
                    'scope' => $declaration->site_id ? 'WEBSITE' : 'PUBLISHER_GLOBAL',
                    'account_id' => null,
                    'account_label' => 'Horus Media seller identity',
                    'status' => $declaration->status,
                    'provenance' => $provenance,
                ];
            }

            $record = $entry['record'];
            if ($sourceType === 'PLATFORM_MASTER') {
                return [
                    'id' => $record->id,
                    'canonical' => $entry['line'],
                    'source' => 'PLATFORM_MASTER',
                    'scope' => 'PLATFORM_GLOBAL',
                    'account_id' => null,
                    'account_label' => 'Horus Media platform master authorization',
                    'status' => $record->status,
                    'relationship' => $record->relationship,
                    'review_status' => $record->review_status?->value ?? (string) $record->review_status,
                    'remote_verification_status' => $record->remote_verification_status,
                    'provenance' => $provenance,
                ];
            }
            if ($sourceType === 'BIDDER_RECORD') {
                return [
                    'id' => $record->id,
                    'canonical' => $entry['line'],
                    'source' => 'PREBID_BIDDER_'.$record->source,
                    'scope' => $record->site_id ? 'WEBSITE' : 'BIDDER_ACCOUNT_GLOBAL',
                    'account_id' => $record->bidder_account_id,
                    'account_label' => $record->account?->name ?: 'Prebid bidder',
                    'status' => $record->status,
                    'relationship' => $record->relationship,
                    'review_status' => $record->review_status?->value ?? (string) $record->review_status,
                    'remote_verification_status' => $this->bidderAdsTxt->effectiveRemoteStatus($record)->value,
                    'provenance' => $provenance,
                ];
            }
            return [
                'id' => $record->id,
                'canonical' => $entry['line'],
                'source' => $record->source,
                'scope' => $record->site_id ? 'WEBSITE' : 'ACCOUNT_GLOBAL',
                'account_id' => $record->demand_account_id,
                'account_label' => $record->account?->network?->name ?: 'Managed demand',
                'status' => $record->status,
                'provenance' => $provenance,
            ];
        })->all();
        $requiredMissing = collect($result['findings'])->where('code', 'BIDDER_ADS_TXT_REQUIRED_MISSING')->count();
        $canonicalConflicts = collect($result['findings'])->where('severity', 'ERROR')->filter(
            fn (array $finding): bool => str_contains((string) ($finding['code'] ?? ''), 'ADS_TXT')
                && str_contains((string) ($finding['code'] ?? ''), 'CONFLICT'),
        )->count();

        return [
            'phase' => 'PRODUCTION',
            'content' => $content,
            'comparison_content' => $content,
            'checksum' => hash('sha256', $content),
            'records' => $records,
            'record_count' => count($result['lines']),
            'required_record_count' => count($result['lines']),
            'required_missing_count' => $requiredMissing,
            'canonical_conflict_count' => $canonicalConflicts,
            'findings' => $result['findings'],
            'parsed' => $this->parser->parse($content),
            'core_records' => [],
        ];
    }

    /** @param array{available: bool, core_records: list<string>, records: list<string>, content: string, ads_txt_url: string} $bundle */
    private function activationCanonical(array $bundle): array
    {
        $core = collect($bundle['core_records'])->map(fn (string $line): string => trim($line))->filter()->values();
        $all = collect($bundle['records'])->map(fn (string $line): string => trim($line))->filter()->values();
        $directives = $all->filter(fn (string $line): bool => preg_match('/^[A-Z][A-Z0-9_-]*\s*=/i', $line) === 1)->values();
        $requiredLines = $directives->merge($core)->unique()->values();
        $comparisonContent = $requiredLines->implode("\n").($requiredLines->isEmpty() ? '' : "\n");
        $records = $all->reject(fn (string $line): bool => preg_match('/^[A-Z][A-Z0-9_-]*\s*=/i', $line) === 1)
            ->map(function (string $line) use ($core): array {
                $isCore = $core->contains($line);
                $isHmp = str_contains($line, ', HMP-');
                $isHms = str_contains($line, ', HMS-');

                return [
                    'id' => hash('sha256', $line),
                    'canonical' => $line,
                    'source' => $isCore ? 'HORUS_ACTIVATION_CORE' : 'ACTIVATION_SUPPORTING',
                    'scope' => $isHmp ? 'PUBLISHER' : ($isHms ? 'WEBSITE' : 'SUPPORTING'),
                    'account_id' => null,
                    'account_label' => $isCore ? 'Horus activation seller identity' : 'Supporting master / demand authorization',
                    'status' => $isCore ? 'REQUIRED' : 'SUPPORTING',
                    'activation_critical' => $isCore,
                    'provenance' => [],
                ];
            })->all();

        return [
            'phase' => 'ACTIVATION',
            'content' => $bundle['content'],
            'comparison_content' => $comparisonContent,
            'checksum' => hash('sha256', $comparisonContent),
            'records' => $records,
            'record_count' => count($records),
            'required_record_count' => $core->count(),
            'required_missing_count' => 0,
            'canonical_conflict_count' => 0,
            'findings' => [],
            'parsed' => $this->parser->parse($bundle['content']),
            'core_records' => $core->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Site $site): array
    {
        $canonical = $this->canonical($site);
        $activationPhase = ($canonical['phase'] ?? 'PRODUCTION') === 'ACTIVATION';
        $bidderRequiredMissing = (int) ($canonical['required_missing_count'] ?? 0);
        $canonicalConflicts = (int) ($canonical['canonical_conflict_count'] ?? 0);
        $latest = SupplyChainCheck::withoutGlobalScope('organization')
            ->where('site_id', $site->id)->where('check_type', 'ADS_TXT')
            ->latest('checked_at')->first();
        $comparisonContent = (string) ($canonical['comparison_content'] ?? $canonical['content']);
        $comparison = $latest ? $this->comparator->compare($comparisonContent, $latest->response_body ?? '', $canonical['findings']) : [];
        $fetchSucceeded = (bool) data_get($latest?->findings, 'fetch.ok', false);
        $canonicalChanged = $latest && $latest->required_checksum !== $canonical['checksum'];
        $currentStatusWins = in_array($comparison['status'] ?? null, [
            AdsTxtComplianceStatus::Conflict->value,
            AdsTxtComplianceStatus::Invalid->value,
            AdsTxtComplianceStatus::NotConfigured->value,
        ], true);
        $baseStatus = $canonicalConflicts > 0
            ? AdsTxtComplianceStatus::Conflict->value
            : ($bidderRequiredMissing > 0
                ? AdsTxtComplianceStatus::Missing->value
                : ($latest
                    ? ($fetchSucceeded || $currentStatusWins ? $comparison['status'] : $latest->status)
                    : (($canonical['required_record_count'] ?? $canonical['record_count']) === 0 ? AdsTxtComplianceStatus::NotConfigured->value : AdsTxtComplianceStatus::Stale->value)));
        $isStale = $latest && $latest->checked_at->lt(now()->subDays((int) config('ads-txt.fresh_for_days', 7)));
        $status = $canonicalConflicts > 0
            ? AdsTxtComplianceStatus::Conflict->value
            : ($bidderRequiredMissing > 0
                ? AdsTxtComplianceStatus::Missing->value
                : (($isStale || $canonicalChanged)
                    && ! in_array($comparison['status'] ?? null, [AdsTxtComplianceStatus::Conflict->value, AdsTxtComplianceStatus::Invalid->value], true)
                    && $baseStatus !== AdsTxtComplianceStatus::NotConfigured->value
                    ? AdsTxtComplianceStatus::Stale->value : $baseStatus));
        $correct = count((array) ($comparison['correct'] ?? []));
        $missing = count((array) ($comparison['missing'] ?? []))
            + ($activationPhase ? 0 : count((array) ($comparison['missing_directives'] ?? [])))
            + $bidderRequiredMissing;
        $invalid = count((array) ($comparison['invalid'] ?? [])) + count((array) ($comparison['conflicts'] ?? [])) + $canonicalConflicts;
        $requiredCount = (int) ($canonical['required_record_count'] ?? $canonical['record_count']) + $bidderRequiredMissing;
        if (! $latest && $requiredCount > 0) {
            $missing += $requiredCount;
        }
        $coreVerified = $activationPhase && $this->installation->hasCurrentCoreVerification($site);

        return [
            'site' => $site,
            'phase' => $canonical['phase'] ?? 'PRODUCTION',
            'status' => $status,
            'required_count' => $requiredCount,
            'correct_count' => min($correct, $requiredCount),
            'missing_count' => min($missing, $requiredCount),
            'invalid_count' => $invalid,
            'last_checked' => $latest?->checked_at,
            'first_checked' => $latest?->first_checked_at,
            'occurrence_count' => $latest?->occurrence_count ?? 0,
            'verification_state' => ! $latest ? 'NEVER_CHECKED' : ($isStale || $canonicalChanged ? 'DUE' : 'FRESH'),
            'next_check_at' => $latest?->checked_at?->copy()->addDays((int) config('ads-txt.fresh_for_days', 7)),
            'action' => $activationPhase
                ? ($coreVerified
                    ? 'Both Horus HMP/HMS DIRECT activation records are verified. Website review may continue; supporting master/demand records do not block activation.'
                    : 'Publish and verify the two Horus HMP/HMS DIRECT records. Website review can continue while verification is pending.')
                : ($canonicalConflicts > 0
                    ? 'Resolve canonical ads.txt source conflicts before publishing.'
                    : ($bidderRequiredMissing > 0
                        ? 'Add and review the missing required Prebid bidder ads.txt authorization before production monetization.'
                        : $this->action($status, $missing, $invalid))),
            'canonical' => $canonical,
            'live_content' => $latest?->response_body,
            'comparison' => $comparison,
            'fetch' => (array) data_get($latest?->findings, 'fetch', []),
            'latest_check' => $latest,
            'core_verified' => $coreVerified,
        ];
    }

    public function history(Site $site)
    {
        return SupplyChainCheck::withoutGlobalScope('organization')->with('initiator')
            ->where('site_id', $site->id)->where('check_type', 'ADS_TXT')->latest('checked_at')->get();
    }

    private function action(string $status, int $missing, int $invalid): string
    {
        return match ($status) {
            AdsTxtComplianceStatus::Compliant->value => 'No action required.',
            AdsTxtComplianceStatus::Partial->value => 'Publish the remaining '.$missing.' required item(s).',
            AdsTxtComplianceStatus::Missing->value => 'Publish the canonical ads.txt file.',
            AdsTxtComplianceStatus::Invalid->value => 'Correct '.$invalid.' invalid or duplicate item(s).',
            AdsTxtComplianceStatus::Conflict->value => 'Resolve conflicting seller authorizations.',
            AdsTxtComplianceStatus::Stale->value => 'Run a fresh verification.',
            AdsTxtComplianceStatus::Unreachable->value => 'Restore a public text/plain ads.txt endpoint.',
            default => 'Configure an eligible authorized-seller record when monetization requires it.',
        };
    }
}
