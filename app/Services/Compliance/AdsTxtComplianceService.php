<?php

namespace App\Services\Compliance;

use App\Enums\AdsTxtComplianceStatus;
use App\Models\Site;
use App\Models\SupplyChainCheck;
use App\Services\Prebid\BidderAdsTxtService;
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
    ) {}

    /** @return array<string, mixed> */
    public function canonical(Site $site): array
    {
        $result = $this->contract->adsTxtForSite($site);
        $content = $this->artifacts->adsTxtForSite($site, $result);
        $entries = $result['entries'] ?? collect($result['records'])->values()->map(
            fn ($record, int $index): array => [
                'record' => $record,
                'declaration' => null,
                'source_type' => 'DEMAND_RECORD',
                'line' => $result['lines'][$index] ?? $record->raw_record,
            ],
        )->all();
        $records = collect($entries)->map(function (array $entry): array {
            if ($entry['source_type'] === 'SELLER_DECLARATION') {
                $declaration = $entry['declaration'];

                return [
                    'id' => $declaration->id,
                    'canonical' => $entry['line'],
                    'source' => 'CANONICAL_SELLER_IDENTITY',
                    'scope' => $declaration->site_id ? 'WEBSITE' : 'PUBLISHER_GLOBAL',
                    'account_id' => null,
                    'account_label' => 'Horus Media seller identity',
                    'status' => $declaration->status,
                ];
            }

            $record = $entry['record'];
            if (($entry['source_type'] ?? null) === 'BIDDER_RECORD') {
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
            ];
        })->all();
        $requiredMissing = collect($result['findings'])->where('code', 'BIDDER_ADS_TXT_REQUIRED_MISSING')->count();

        return [
            'content' => $content,
            'checksum' => hash('sha256', $content),
            'records' => $records,
            'record_count' => count($result['lines']),
            'required_missing_count' => $requiredMissing,
            'findings' => $result['findings'],
            'parsed' => $this->parser->parse($content),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Site $site): array
    {
        $canonical = $this->canonical($site);
        $bidderRequiredMissing = (int) ($canonical['required_missing_count'] ?? 0);
        $latest = SupplyChainCheck::withoutGlobalScope('organization')
            ->where('site_id', $site->id)->where('check_type', 'ADS_TXT')
            ->latest('checked_at')->first();
        $comparison = $latest
            ? $this->comparator->compare($canonical['content'], $latest->response_body ?? '', $canonical['findings'])
            : [];
        $fetchSucceeded = (bool) data_get($latest?->findings, 'fetch.ok', false);
        $canonicalChanged = $latest && $latest->required_checksum !== $canonical['checksum'];
        $currentStatusWins = in_array($comparison['status'] ?? null, [
            AdsTxtComplianceStatus::Conflict->value,
            AdsTxtComplianceStatus::Invalid->value,
            AdsTxtComplianceStatus::NotConfigured->value,
        ], true);
        $baseStatus = $bidderRequiredMissing > 0
            ? AdsTxtComplianceStatus::Missing->value
            : ($latest
                ? ($fetchSucceeded || $currentStatusWins ? $comparison['status'] : $latest->status)
                : ($canonical['record_count'] === 0
                    ? AdsTxtComplianceStatus::NotConfigured->value
                    : AdsTxtComplianceStatus::Stale->value));
        $isStale = $latest && $latest->checked_at->lt(now()->subDays((int) config('ads-txt.fresh_for_days', 7)));
        $status = $bidderRequiredMissing > 0
            ? AdsTxtComplianceStatus::Missing->value
            : (($isStale || $canonicalChanged)
                && ! in_array($comparison['status'] ?? null, [AdsTxtComplianceStatus::Conflict->value, AdsTxtComplianceStatus::Invalid->value], true)
                && $baseStatus !== AdsTxtComplianceStatus::NotConfigured->value
                ? AdsTxtComplianceStatus::Stale->value
                : $baseStatus);
        $correct = count((array) ($comparison['correct'] ?? []));
        $missing = count((array) ($comparison['missing'] ?? [])) + count((array) ($comparison['missing_directives'] ?? [])) + $bidderRequiredMissing;
        $invalid = count((array) ($comparison['invalid'] ?? [])) + count((array) ($comparison['conflicts'] ?? []));
        if (! $latest && $canonical['record_count'] > 0) {
            $missing += $canonical['record_count'];
        }
        $requiredCount = $canonical['record_count'] + $bidderRequiredMissing;

        return [
            'site' => $site,
            'status' => $status,
            'required_count' => $requiredCount,
            'correct_count' => $correct,
            'missing_count' => $missing,
            'invalid_count' => $invalid,
            'last_checked' => $latest?->checked_at,
            'first_checked' => $latest?->first_checked_at,
            'occurrence_count' => $latest?->occurrence_count ?? 0,
            'verification_state' => ! $latest ? 'NEVER_CHECKED' : ($isStale || $canonicalChanged ? 'DUE' : 'FRESH'),
            'next_check_at' => $latest?->checked_at?->copy()->addDays((int) config('ads-txt.fresh_for_days', 7)),
            'action' => $bidderRequiredMissing > 0
                ? 'Add and review the missing required Prebid bidder ads.txt authorization before production monetization.'
                : $this->action($status, $missing, $invalid),
            'canonical' => $canonical,
            'live_content' => $latest?->response_body,
            'comparison' => $comparison,
            'fetch' => (array) data_get($latest?->findings, 'fetch', []),
            'latest_check' => $latest,
        ];
    }

    public function history(Site $site)
    {
        return SupplyChainCheck::withoutGlobalScope('organization')
            ->with('initiator')->where('site_id', $site->id)->where('check_type', 'ADS_TXT')
            ->latest('checked_at')->get();
    }

    private function action(string $status, int $missing, int $invalid): string
    {
        return match ($status) {
            AdsTxtComplianceStatus::Compliant->value => 'No action required.',
            AdsTxtComplianceStatus::Partial->value => 'Publish the remaining '.$missing.' required item(s).',
            AdsTxtComplianceStatus::Missing->value => 'Publish the canonical ads.txt file.',
            AdsTxtComplianceStatus::Invalid->value => 'Correct '.$invalid.' invalid or duplicate item(s).',
            AdsTxtComplianceStatus::Conflict->value => 'Resolve conflicting seller declarations.',
            AdsTxtComplianceStatus::Stale->value => 'Run a fresh verification.',
            AdsTxtComplianceStatus::Unreachable->value => 'Restore a public text/plain ads.txt endpoint.',
            default => 'Configure an eligible authorized-seller record when monetization requires it.',
        };
    }
}
