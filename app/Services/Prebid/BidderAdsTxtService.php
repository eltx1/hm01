<?php

namespace App\Services\Prebid;

use App\Enums\BidderAdsTxtRequirement;
use App\Enums\BidderSellersJsonStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\BidderAccount;
use App\Models\BidderAdsTxtRecord;
use App\Models\BidderSiteMapping;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\SupplyChain\DomainNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class BidderAdsTxtService
{
    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly AuditRecorder $audit,
    ) {}

    public function updateRequirement(BidderAccount $account, array $attributes, User $actor): BidderAccount
    {
        $requirement = $attributes['ads_txt_requirement'] instanceof BidderAdsTxtRequirement
            ? $attributes['ads_txt_requirement']
            : BidderAdsTxtRequirement::from(strtoupper((string) $attributes['ads_txt_requirement']));
        $url = trim((string) ($attributes['ads_txt_evidence_url'] ?? ''));
        if ($url !== '' && (! str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            throw ValidationException::withMessages(['ads_txt_evidence_url' => 'Evidence must be a valid HTTPS URL.']);
        }

        $before = $account->only(['ads_txt_requirement', 'ads_txt_evidence_url', 'ads_txt_requirement_verified_at', 'ads_txt_requirement_reviewed_by']);
        $account->update([
            'ads_txt_requirement' => $requirement,
            'ads_txt_evidence_url' => $url !== '' ? $url : null,
            'ads_txt_requirement_verified_at' => $attributes['ads_txt_requirement_verified_at'] ?? now(),
            'ads_txt_requirement_reviewed_by' => $actor->id,
        ]);
        $this->audit->record('prebid.bidder_ads_txt_requirement.updated', $account->organization_id, $actor, $account, $before, $account->fresh()->only(array_keys($before)));

        return $account->refresh();
    }

    public function create(BidderAccount $account, ?Site $site, array $attributes, User $actor): BidderAdsTxtRecord
    {
        $normalized = $this->normalize($account, $site, $attributes);
        $this->assertUnique($account, $site, $normalized['record_hash']);

        $record = BidderAdsTxtRecord::withoutGlobalScopes()->create($normalized + [
            'bidder_account_id' => $account->id,
            'site_id' => $site?->id,
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::ReviewRequired,
            'source' => 'MANUAL',
            'remote_verification_status' => BidderSellersJsonStatus::Unverified,
        ]);
        $this->audit->record('prebid.bidder_ads_txt_record.created', $account->organization_id, $actor, $record, newValues: $this->auditValues($record));

        return $record;
    }

    public function update(BidderAdsTxtRecord $record, ?Site $site, array $attributes, User $actor): BidderAdsTxtRecord
    {
        $account = BidderAccount::withoutGlobalScopes()->findOrFail($record->bidder_account_id);
        $normalized = $this->normalize($account, $site, $attributes);
        $this->assertUnique($account, $site, $normalized['record_hash'], $record);
        $before = $this->auditValues($record);
        $record->update($normalized + [
            'site_id' => $site?->id,
            'review_status' => SupplyChainReviewStatus::ReviewRequired,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'last_verified_at' => null,
            'remote_verification_status' => BidderSellersJsonStatus::Unverified,
            'remote_verified_at' => null,
            'remote_error_code' => null,
        ]);
        $this->audit->record('prebid.bidder_ads_txt_record.updated', $account->organization_id, $actor, $record, $before, $this->auditValues($record));

        return $record->refresh();
    }

    public function disable(BidderAdsTxtRecord $record, User $actor): BidderAdsTxtRecord
    {
        $before = $this->auditValues($record);
        $record->update(['status' => 'DISABLED']);
        $this->audit->record('prebid.bidder_ads_txt_record.disabled', $record->organization_id, $actor, $record, $before, $this->auditValues($record));

        return $record->refresh();
    }

    public function review(BidderAdsTxtRecord $record, SupplyChainReviewStatus $status, User $actor): BidderAdsTxtRecord
    {
        if ($status === SupplyChainReviewStatus::Verified) {
            $account = BidderAccount::withoutGlobalScopes()->findOrFail($record->bidder_account_id);
            $site = $record->site_id ? Site::withoutGlobalScopes()->findOrFail($record->site_id) : null;
            $this->normalize($account, $site, $record->toArray());
        }
        $before = $this->auditValues($record);
        $record->update([
            'review_status' => $status,
            'reviewed_at' => $status === SupplyChainReviewStatus::ReviewRequired ? null : now(),
            'reviewed_by' => $status === SupplyChainReviewStatus::ReviewRequired ? null : $actor->id,
            'last_verified_at' => $status === SupplyChainReviewStatus::Verified ? now() : $record->last_verified_at,
            'status' => $status === SupplyChainReviewStatus::Rejected ? 'DISABLED' : $record->status,
        ]);
        $this->audit->record('prebid.bidder_ads_txt_record.reviewed', $record->organization_id, $actor, $record, $before, $this->auditValues($record));

        return $record->refresh();
    }

    /** @return array{entries: array<int,array<string,mixed>>, findings: array<int,array<string,string>>} */
    public function entriesForSite(Site $site): array
    {
        $mappings = $this->eligibleMappings($site);
        if ($mappings->isEmpty()) {
            return ['entries' => [], 'findings' => []];
        }
        $accountIds = $mappings->pluck('bidder_account_id')->unique()->values();
        $records = BidderAdsTxtRecord::withoutGlobalScopes()
            ->with('account.bidder')
            ->whereIn('bidder_account_id', $accountIds)
            ->where('status', 'ACTIVE')
            ->where('review_status', SupplyChainReviewStatus::Verified->value)
            ->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $site->id))
            ->orderBy('advertising_system_domain')->orderBy('publisher_account_id')->orderBy('relationship')->orderBy('id')
            ->get();

        $findings = $this->requirementFindings($mappings, $records, $site);
        $entries = $records->map(function (BidderAdsTxtRecord $record) use ($site, &$findings): ?array {
            $account = $record->account;
            if (! $account || $record->organization_id !== $account->organization_id) {
                $findings[] = $this->finding('BIDDER_ADS_TXT_ENTITY_MISMATCH', 'ERROR', 'A bidder ads.txt record does not match its bidder account.');
                return null;
            }
            if ($record->site_id && $record->site_id !== $site->id) {
                return null;
            }
            try {
                $line = $this->line($record);
            } catch (InvalidArgumentException) {
                $findings[] = $this->finding('BIDDER_ADS_TXT_INVALID', 'ERROR', 'A reviewed bidder ads.txt record contains invalid fields.');
                return null;
            }

            return [
                'record' => $record,
                'declaration' => null,
                'source_type' => 'BIDDER_RECORD',
                'line' => $line,
                'key' => strtolower($record->advertising_system_domain)."\0".$record->publisher_account_id,
                'sort_key' => implode('|', ['3', $record->site_id ? '1' : '2', $record->bidder_account_id, $record->id]),
            ];
        })->filter()->values()->all();

        return ['entries' => $entries, 'findings' => $findings];
    }

    /** @return array{required_missing:int,unknown:int,required:int,not_required:int,findings:array} */
    public function readinessForSite(Site $site): array
    {
        $result = $this->entriesForSite($site);
        $codes = collect($result['findings'])->pluck('code');
        $mappings = $this->eligibleMappings($site);

        return [
            'required_missing' => $codes->filter(fn ($code) => $code === 'BIDDER_ADS_TXT_REQUIRED_MISSING')->count(),
            'unknown' => $mappings->filter(fn ($mapping) => $mapping->account?->ads_txt_requirement === BidderAdsTxtRequirement::Unknown)->count(),
            'required' => $mappings->filter(fn ($mapping) => $mapping->account?->ads_txt_requirement === BidderAdsTxtRequirement::Required)->count(),
            'not_required' => $mappings->filter(fn ($mapping) => $mapping->account?->ads_txt_requirement === BidderAdsTxtRequirement::NotRequired)->count(),
            'findings' => $result['findings'],
        ];
    }

    public function effectiveRemoteStatus(BidderAdsTxtRecord $record): BidderSellersJsonStatus
    {
        if ($record->remote_verification_status === BidderSellersJsonStatus::Verified
            && $record->remote_verified_at?->lt(now()->subDays(30))) {
            return BidderSellersJsonStatus::Stale;
        }

        return $record->remote_verification_status ?? BidderSellersJsonStatus::Unverified;
    }

    /** @return Collection<int,BidderSiteMapping> */
    private function eligibleMappings(Site $site): Collection
    {
        if (! $site->prebid_enabled) {
            return collect();
        }

        return BidderSiteMapping::withoutGlobalScopes()
            ->with('account.bidder')
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->get()
            ->filter(fn (BidderSiteMapping $mapping): bool => $mapping->account
                && $mapping->account->enabled
                && $mapping->account->bidder
                && $mapping->account->bidder->enabled);
    }

    private function normalize(BidderAccount $account, ?Site $site, array $attributes): array
    {
        if ($site && ! BidderSiteMapping::withoutGlobalScopes()->where('bidder_account_id', $account->id)->where('site_id', $site->id)->exists()) {
            throw ValidationException::withMessages(['site_id' => 'Site-specific bidder ads.txt records require an explicit bidder account mapping.']);
        }
        try {
            $domain = $this->domains->normalize((string) ($attributes['advertising_system_domain'] ?? $attributes['domain'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['advertising_system_domain' => 'Advertising system domain is invalid.']);
        }
        $publisherId = trim((string) ($attributes['publisher_account_id'] ?? ''));
        $relationship = strtoupper(trim((string) ($attributes['relationship'] ?? '')));
        $authority = strtolower(trim((string) ($attributes['certification_authority_id'] ?? '')));
        if ($publisherId === '' || strlen($publisherId) > 255 || preg_match('/[\s,\x00-\x1F\x7F]/u', $publisherId)) {
            throw ValidationException::withMessages(['publisher_account_id' => 'Bidder ads.txt seller ID is invalid.']);
        }
        if (! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            throw ValidationException::withMessages(['relationship' => 'Bidder ads.txt relationship must be explicitly DIRECT or RESELLER.']);
        }
        if ($authority !== '' && (strlen($authority) > 128 || preg_match('/^[a-z0-9._-]+$/', $authority) !== 1)) {
            throw ValidationException::withMessages(['certification_authority_id' => 'Certification authority ID is invalid.']);
        }
        $line = implode(', ', array_filter([$domain, $publisherId, $relationship, $authority], fn (string $value): bool => $value !== ''));

        return [
            'organization_id' => $account->organization_id,
            'advertising_system_domain' => $domain,
            'publisher_account_id' => $publisherId,
            'relationship' => $relationship,
            'certification_authority_id' => $authority ?: null,
            'raw_record' => $line,
            'record_hash' => hash('sha256', $line),
        ];
    }

    private function line(BidderAdsTxtRecord $record): string
    {
        $domain = $this->domains->normalize($record->advertising_system_domain);
        $relationship = strtoupper(trim((string) $record->relationship));
        if (! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            throw new InvalidArgumentException('Invalid relationship.');
        }

        return implode(', ', array_filter([$domain, trim($record->publisher_account_id), $relationship, $record->certification_authority_id], fn ($value) => filled($value)));
    }

    private function assertUnique(BidderAccount $account, ?Site $site, string $hash, ?BidderAdsTxtRecord $except = null): void
    {
        $exists = BidderAdsTxtRecord::withoutGlobalScopes()
            ->where('bidder_account_id', $account->id)
            ->when($site, fn ($q) => $q->where('site_id', $site->id), fn ($q) => $q->whereNull('site_id'))
            ->where('record_hash', $hash)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['publisher_account_id' => 'This bidder ads.txt record already exists for the selected scope.']);
        }
    }

    private function requirementFindings(Collection $mappings, Collection $records, Site $site): array
    {
        $findings = [];
        foreach ($mappings as $mapping) {
            $account = $mapping->account;
            $requirement = $account?->ads_txt_requirement ?? BidderAdsTxtRequirement::Unknown;
            $hasRecord = $records->contains(fn (BidderAdsTxtRecord $record): bool => $record->bidder_account_id === $account->id
                && ($record->site_id === null || $record->site_id === $site->id));
            if ($requirement === BidderAdsTxtRequirement::Required && ! $hasRecord) {
                $findings[] = $this->finding('BIDDER_ADS_TXT_REQUIRED_MISSING', 'ERROR', 'An eligible Prebid bidder requires ads.txt authorization, but no reviewed active record applies to this website.', $account->id);
            } elseif ($requirement === BidderAdsTxtRequirement::Unknown) {
                $findings[] = $this->finding('BIDDER_ADS_TXT_REQUIREMENT_UNKNOWN', 'WARNING', 'The ads.txt requirement for an eligible Prebid bidder account is UNKNOWN and has not been guessed.', $account->id);
            }
        }

        return $findings;
    }

    private function finding(string $code, string $severity, string $message, ?string $accountId = null): array
    {
        return array_filter(['code' => $code, 'severity' => $severity, 'message' => $message, 'bidder_account_id' => $accountId]);
    }

    private function auditValues(BidderAdsTxtRecord $record): array
    {
        return $record->only([
            'bidder_account_id', 'site_id', 'advertising_system_domain', 'publisher_account_id',
            'relationship', 'certification_authority_id', 'status', 'review_status', 'source',
            'remote_verification_status', 'remote_error_code',
        ]);
    }
}
