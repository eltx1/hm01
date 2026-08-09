<?php

namespace App\Services\SupplyChain;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandSite;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SupplyChainInvariantService
{
    public const OWNER_DOMAIN_DECLARED = 'PUBLISHER_BUSINESS_DOMAIN';

    public const OWNER_DOMAIN_LEGACY_FALLBACK = 'LEGACY_SITE_DOMAIN';

    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function publisherIdentityAttributes(?Publisher $publisher, ?string $businessDomain): array
    {
        $domain = $this->validatedDomain($businessDomain, 'business_domain');
        try {
            $current = $publisher?->business_domain ? $this->domains->normalize($publisher->business_domain) : null;
        } catch (InvalidArgumentException) {
            $current = "\0INVALID";
        }

        $attributes = ['business_domain' => $domain];
        if (! $publisher || $domain !== $current) {
            $attributes += [
                'supply_chain_review_status' => SupplyChainReviewStatus::ReviewRequired,
                'supply_chain_reviewed_at' => null,
                'supply_chain_reviewed_by' => null,
            ];
        }

        return $attributes;
    }

    public function reviewPublisherIdentity(
        Publisher $publisher,
        SupplyChainReviewStatus $status,
        User $actor,
    ): Publisher {
        if ($status === SupplyChainReviewStatus::Verified && blank($publisher->business_domain)) {
            throw ValidationException::withMessages([
                'business_domain' => 'A publisher business domain is required before supply-chain identity can be verified.',
            ]);
        }

        $before = $publisher->only([
            'business_domain', 'supply_chain_review_status', 'supply_chain_reviewed_at', 'supply_chain_reviewed_by',
        ]);
        $publisher->update([
            'supply_chain_review_status' => $status,
            'supply_chain_reviewed_at' => $status === SupplyChainReviewStatus::ReviewRequired ? null : now(),
            'supply_chain_reviewed_by' => $status === SupplyChainReviewStatus::ReviewRequired ? null : $actor->id,
        ]);
        $this->audit->record(
            'supply_chain.publisher_identity.reviewed',
            $publisher->organization_id,
            $actor,
            $publisher,
            $before,
            $publisher->only(array_keys($before)),
        );

        return $publisher->refresh();
    }

    public function saveSellerDeclaration(
        Publisher $publisher,
        ?Site $site,
        array $attributes,
        User $actor,
    ): SellerDeclaration {
        $this->assertPublisherSite($publisher, $site);
        $sellerId = trim((string) ($attributes['seller_id'] ?? ''));
        if ($sellerId === '' || strlen($sellerId) > 255 || preg_match('/[\x00-\x1F\x7F,]/', $sellerId)) {
            throw ValidationException::withMessages(['seller_id' => 'Seller ID must be a non-empty value without commas or control characters.']);
        }

        $sellerType = strtoupper(trim((string) ($attributes['seller_type'] ?? '')));
        if (! in_array($sellerType, ['PUBLISHER', 'INTERMEDIARY', 'BOTH'], true)) {
            throw ValidationException::withMessages(['seller_type' => 'Seller type must be PUBLISHER, INTERMEDIARY, or BOTH.']);
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'An internal seller identity name is required.']);
        }
        $domain = $this->validatedDomain($attributes['domain'] ?? null, 'domain');
        if (! $domain) {
            throw ValidationException::withMessages(['domain' => 'An internal seller business domain is required.']);
        }
        if ($publisher->business_domain && ! $this->domains->same($publisher->business_domain, $domain)) {
            throw ValidationException::withMessages([
                'domain' => 'Seller domain must match the publisher business domain. Website domains are represented by the Site instead.',
            ]);
        }

        $isConfidential = (bool) ($attributes['is_confidential'] ?? false);
        $status = strtoupper((string) ($attributes['status'] ?? 'ACTIVE'));
        if (! in_array($status, ['ACTIVE', 'DISABLED'], true)) {
            throw ValidationException::withMessages(['status' => 'Seller status must be ACTIVE or DISABLED.']);
        }

        return DB::transaction(function () use ($publisher, $site, $sellerId, $sellerType, $name, $domain, $isConfidential, $status, $actor): SellerDeclaration {
            $declaration = SellerDeclaration::withoutGlobalScope('organization')->firstOrNew([
                'organization_id' => $publisher->organization_id,
                'site_id' => $site?->id,
                'seller_id' => $sellerId,
            ]);
            $candidate = [
                'publisher_id' => $publisher->id,
                'seller_id' => $sellerId,
                'seller_type' => $sellerType,
                'name' => $name,
                'domain' => $domain,
                'is_confidential' => $isConfidential,
            ];
            $this->assertSellerIdAvailable($declaration, $candidate);

            $before = $declaration->exists ? $this->safeSellerValues($declaration) : [];
            $identityChanged = ! $declaration->exists || collect($candidate)->contains(
                fn (mixed $value, string $key): bool => $declaration->getAttribute($key) != $value,
            );
            $declaration->fill($candidate + [
                'organization_id' => $publisher->organization_id,
                'site_id' => $site?->id,
                'status' => $status,
            ]);
            if ($identityChanged) {
                $declaration->fill([
                    'review_status' => SupplyChainReviewStatus::ReviewRequired,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'last_verified_at' => null,
                ]);
            }
            $declaration->save();

            $this->audit->record(
                'supply_chain.seller.updated',
                $publisher->organization_id,
                $actor,
                $declaration,
                $before,
                $this->safeSellerValues($declaration),
            );

            return $declaration->refresh();
        });
    }

    public function reviewSellerDeclaration(
        SellerDeclaration $declaration,
        SupplyChainReviewStatus $status,
        User $actor,
    ): SellerDeclaration {
        $before = $this->safeSellerValues($declaration);
        $declaration->update([
            'review_status' => $status,
            'reviewed_at' => $status === SupplyChainReviewStatus::ReviewRequired ? null : now(),
            'reviewed_by' => $status === SupplyChainReviewStatus::ReviewRequired ? null : $actor->id,
            'last_verified_at' => $status === SupplyChainReviewStatus::Verified ? now() : $declaration->last_verified_at,
        ]);
        $this->audit->record(
            'supply_chain.seller.reviewed',
            $declaration->organization_id,
            $actor,
            $declaration,
            $before,
            $this->safeSellerValues($declaration),
        );

        return $declaration->refresh();
    }

    /** @return array<string, mixed> */
    public function normalizeDemandRecord(DemandAccount $account, ?Site $site, array $record): array
    {
        if ($site) {
            $mapping = DemandSite::withoutGlobalScope('organization')
                ->where('demand_account_id', $account->id)
                ->where('site_id', $site->id)
                ->first();
            if (! $mapping) {
                throw ValidationException::withMessages(['site_id' => 'Site-specific Ads.txt records require an explicit demand-account site mapping.']);
            }
            if ($account->scope === DemandAccountScope::Publisher && $account->publisher_id !== $site->publisher_id) {
                throw ValidationException::withMessages(['site_id' => 'The publisher-scoped demand account does not belong to this website publisher.']);
            }
        }

        $domain = $this->validatedDomain($record['domain'] ?? null, 'domain');
        $publisherAccountId = trim((string) ($record['publisher_account_id'] ?? ''));
        $relationship = strtoupper(trim((string) ($record['relationship'] ?? '')));
        $authority = strtolower(trim((string) ($record['certification_authority_id'] ?? '')));
        if (! $domain || $publisherAccountId === '' || strlen($publisherAccountId) > 255 || preg_match('/[\x00-\x1F\x7F,]/', $publisherAccountId)) {
            throw ValidationException::withMessages(['publisher_account_id' => 'Ads.txt publisher account ID is invalid.']);
        }
        if (! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            throw ValidationException::withMessages(['relationship' => 'Ads.txt relationship must be DIRECT or RESELLER.']);
        }
        if ($authority !== '' && (strlen($authority) > 128 || preg_match('/^[a-z0-9._-]+$/', $authority) !== 1)) {
            throw ValidationException::withMessages(['certification_authority_id' => 'Certification authority ID is invalid.']);
        }

        $line = implode(', ', array_filter([$domain, $publisherAccountId, $relationship, $authority], fn (string $value): bool => $value !== ''));

        return [
            'organization_id' => $account->organization_id,
            'domain' => $domain,
            'publisher_account_id' => $publisherAccountId,
            'relationship' => $relationship,
            'certification_authority_id' => $authority ?: null,
            'raw_record' => $line,
            'record_hash' => hash('sha256', $line),
        ];
    }

    /** @return array{domain: string, source: string, reviewStatus: string, findings: array<int, array<string, string>>} */
    public function ownerIdentity(Site $site): array
    {
        $site->loadMissing('publisher');
        $publisher = $site->publisher;
        $reviewStatus = $publisher?->supply_chain_review_status instanceof SupplyChainReviewStatus
            ? $publisher->supply_chain_review_status->value
            : (string) ($publisher?->supply_chain_review_status ?: SupplyChainReviewStatus::ReviewRequired->value);

        if ($publisher?->business_domain) {
            try {
                return [
                    'domain' => (string) $this->domains->normalize($publisher->business_domain),
                    'source' => self::OWNER_DOMAIN_DECLARED,
                    'reviewStatus' => $reviewStatus,
                    'findings' => [],
                ];
            } catch (InvalidArgumentException) {
                // Legacy or directly-imported invalid values fail over without blocking serving.
            }
        }

        return [
            'domain' => (string) $this->domains->normalize($site->primary_domain),
            'source' => self::OWNER_DOMAIN_LEGACY_FALLBACK,
            'reviewStatus' => SupplyChainReviewStatus::ReviewRequired->value,
            'findings' => [[
                'code' => 'OWNER_DOMAIN_REVIEW_REQUIRED',
                'severity' => 'WARNING',
                'message' => 'OWNERDOMAIN is temporarily using the website domain because no reviewed publisher business domain is available.',
            ]],
        ];
    }

    /** @return array{records: array<int, DemandAdsTxtRecord>, lines: array<int, string>, findings: array<int, array<string, string>>} */
    public function adsTxtForSite(Site $site): array
    {
        $records = DemandAdsTxtRecord::withoutGlobalScope('organization')
            ->with(['account.network'])
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->where('site_id', $site->id)->orWhereNull('site_id'))
            ->orderBy('domain')->orderBy('publisher_account_id')->orderBy('relationship')->orderBy('id')
            ->get();
        $mappings = DemandSite::withoutGlobalScope('organization')
            ->where('site_id', $site->id)
            ->whereIn('demand_account_id', $records->pluck('demand_account_id')->unique())
            ->get()
            ->keyBy('demand_account_id');
        $findings = [];
        $eligible = $records->filter(function (DemandAdsTxtRecord $record) use ($site, $mappings, &$findings): bool {
            $account = $record->account;
            $mapping = $mappings->get($record->demand_account_id);
            if (! $account || ! $account->is_enabled || $account->approval_status !== DemandApprovalStatus::Approved
                || ! $account->network?->is_enabled || ! $mapping || ! $mapping->is_enabled
                || $mapping->approval_status !== DemandApprovalStatus::Approved) {
                return false;
            }
            if ($account->scope === DemandAccountScope::Publisher && $account->publisher_id !== $site->publisher_id) {
                $findings[] = $this->finding('DEMAND_ACCOUNT_PUBLISHER_MISMATCH', 'ERROR', 'A publisher-scoped demand record is mapped to another publisher.');

                return false;
            }
            if ($record->organization_id !== $account->organization_id) {
                $findings[] = $this->finding('DEMAND_RECORD_ORGANIZATION_MISMATCH', 'ERROR', 'An Ads.txt record organization does not match its demand account.');

                return false;
            }

            return true;
        })->map(function (DemandAdsTxtRecord $record) use (&$findings): ?array {
            try {
                $line = $this->adsTxtLine($record);
            } catch (InvalidArgumentException) {
                $findings[] = $this->finding('INVALID_ADS_TXT_RECORD', 'ERROR', 'An active Ads.txt record contains an invalid domain or field value.');

                return null;
            }

            return ['record' => $record, 'line' => $line, 'key' => $this->adsTxtIdentityKey($record)];
        })->filter()->values();

        $selected = collect();
        foreach ($eligible->groupBy('key') as $group) {
            if ($group->pluck('line')->unique()->count() > 1) {
                $findings[] = $this->finding('ADS_TXT_RELATIONSHIP_CONFLICT', 'ERROR', 'The same advertising-system seller ID has conflicting relationship or authority fields.');

                continue;
            }
            if ($group->count() > 1) {
                $findings[] = $this->finding('DUPLICATE_ADS_TXT_RECORD', 'WARNING', 'Equivalent global or site Ads.txt records were collapsed to one canonical line.');
            }
            $selected->push($group->sortBy(fn (array $item): string => implode('|', [
                $item['record']->site_id ? '0' : '1',
                $item['record']->demand_account_id,
                $item['record']->id,
            ]))->first());
        }

        $selected = $selected->sortBy('line')->values();

        return [
            'records' => $selected->pluck('record')->all(),
            'lines' => $selected->pluck('line')->all(),
            'findings' => $this->uniqueFindings($findings),
        ];
    }

    /** @return array{sellers: array<int, array<string, mixed>>, findings: array<int, array<string, string>>} */
    public function sellers(): array
    {
        $declarations = SellerDeclaration::withoutGlobalScope('organization')
            ->with(['publisher', 'site.publisher'])
            ->where('status', 'ACTIVE')
            ->orderBy('seller_id')->orderBy('site_id')->orderBy('id')->get();
        $findings = [];
        $valid = $declarations->map(function (SellerDeclaration $declaration) use (&$findings): ?array {
            $publisher = $this->sellerPublisher($declaration);
            if (! $publisher || ($declaration->site_id && ! $declaration->site)
                || $publisher->organization_id !== $declaration->organization_id
                || ($declaration->site && $declaration->site->publisher_id !== $publisher->id)) {
                $findings[] = $this->finding('SELLER_ENTITY_MISMATCH', 'ERROR', 'A seller declaration is not mapped to its publisher entity.');

                return null;
            }

            try {
                $domain = (string) $this->domains->normalize($declaration->domain);
            } catch (InvalidArgumentException) {
                $findings[] = $this->finding('SELLER_DOMAIN_INVALID', 'ERROR', 'A seller declaration contains an invalid business domain.');

                return null;
            }
            $type = strtoupper((string) $declaration->seller_type);
            if (! in_array($type, ['PUBLISHER', 'INTERMEDIARY', 'BOTH'], true) || blank($declaration->name)) {
                $findings[] = $this->finding('SELLER_IDENTITY_INCOMPLETE', 'ERROR', 'A seller declaration has an invalid type or incomplete internal identity.');

                return null;
            }
            if ($publisher->business_domain && ! $this->domains->same($publisher->business_domain, $domain)) {
                $findings[] = $this->finding('SELLER_DOMAIN_CONFLICT', 'ERROR', 'A seller public domain conflicts with the publisher business domain.');

                return null;
            }

            $confidential = (bool) $declaration->is_confidential;
            $payload = [
                'seller_id' => $declaration->seller_id,
                'seller_type' => $type,
                'name' => $confidential ? null : trim((string) $declaration->name),
                'domain' => $confidential ? null : $domain,
                'is_confidential' => $confidential ? 1 : 0,
            ];

            return [
                'declaration' => $declaration,
                'publisher_id' => $publisher->id,
                'payload' => $payload,
                'fingerprint' => hash('sha256', json_encode([$publisher->id, $payload], JSON_THROW_ON_ERROR)),
            ];
        })->filter()->values();

        $sellers = collect();
        foreach ($valid->groupBy(fn (array $item): string => (string) $item['payload']['seller_id']) as $group) {
            if ($group->pluck('fingerprint')->unique()->count() > 1) {
                $findings[] = $this->finding('SELLER_ID_CONFLICT', 'ERROR', 'A seller ID maps to more than one entity or public identity.');

                continue;
            }
            if ($group->count() > 1) {
                $findings[] = $this->finding('DUPLICATE_SELLER_DECLARATION', 'WARNING', 'Equivalent seller declarations were collapsed to one sellers.json entry.');
            }
            $sellers->push($group->first());
        }

        return [
            'sellers' => $sellers->sortBy(fn (array $item): string => (string) $item['payload']['seller_id'])->values()->all(),
            'findings' => $this->uniqueFindings($findings),
        ];
    }

    /** @return array{complete: int, ver: string, nodes: array<int, array{asi: string, sid: string, hp: int}>, findings: array<int, array<string, string>>} */
    public function schainForSite(Site $site): array
    {
        $network = $this->sellers();
        $publishedSellerIds = collect($network['sellers'])->pluck('payload.seller_id')->map(fn ($id): string => (string) $id);
        $candidates = SellerDeclaration::withoutGlobalScope('organization')
            ->with(['publisher', 'site.publisher'])
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $site->id))
            ->orderBy('seller_id')->orderBy('id')->get()
            ->filter(fn (SellerDeclaration $seller): bool => $this->sellerPublisher($seller)?->id === $site->publisher_id)
            ->filter(fn (SellerDeclaration $seller): bool => $publishedSellerIds->contains((string) $seller->seller_id));
        $sellerIds = $candidates->pluck('seller_id')->map(fn ($id): string => (string) $id)->unique()->values();
        $findings = $network['findings'];
        if ($sellerIds->count() > 1) {
            $findings[] = $this->finding('SITE_SELLER_CONFLICT', 'ERROR', 'The website resolves to multiple active Horus seller IDs; schain cannot be selected safely.');
        }

        $managerDomain = null;
        try {
            $managerDomain = $this->domains->normalize((string) config('supply-chain.manager_domain', 'horusmedia.net'));
        } catch (InvalidArgumentException) {
            $findings[] = $this->finding('MANAGER_DOMAIN_INVALID', 'ERROR', 'The configured Horus manager domain is invalid.');
        }

        if ($sellerIds->count() !== 1 || ! $managerDomain) {
            if ($sellerIds->isEmpty()) {
                $findings[] = $this->finding('SITE_SELLER_MISSING', 'WARNING', 'No unambiguous active seller identity is available for this website.');
            }

            return ['complete' => 0, 'ver' => '1.0', 'nodes' => [], 'findings' => $this->uniqueFindings($findings)];
        }

        $sellerType = strtoupper((string) $candidates->first()->seller_type);
        $complete = $sellerType === 'INTERMEDIARY' ? 0 : 1;
        if ($complete === 0) {
            $findings[] = $this->finding(
                'SCHAIN_UPSTREAM_IDENTITY_REQUIRED',
                'WARNING',
                'The selected seller is an intermediary; an upstream inventory-owner node is required before schain can be complete.',
            );
        }

        return [
            'complete' => $complete,
            'ver' => '1.0',
            'nodes' => [['asi' => $managerDomain, 'sid' => $sellerIds->first(), 'hp' => 1]],
            'findings' => $this->uniqueFindings($findings),
        ];
    }

    /** @return array<int, array<string, string>> */
    public function findingsForSite(Site $site): array
    {
        return $this->uniqueFindings(array_merge(
            $this->ownerIdentity($site)['findings'],
            $this->adsTxtForSite($site)['findings'],
            $this->schainForSite($site)['findings'],
        ));
    }

    public function managerDomain(): string
    {
        return (string) $this->domains->normalize((string) config('supply-chain.manager_domain', 'horusmedia.net'));
    }

    public function adsTxtLine(DemandAdsTxtRecord $record): string
    {
        $domain = $this->domains->normalize($record->domain);
        $publisher = trim((string) $record->publisher_account_id);
        $relationship = strtoupper(trim((string) $record->relationship));
        $authority = strtolower(trim((string) $record->certification_authority_id));
        if (! $domain || $publisher === '' || preg_match('/[\x00-\x1F\x7F,]/', $publisher)
            || ! in_array($relationship, ['DIRECT', 'RESELLER'], true)
            || ($authority !== '' && preg_match('/^[a-z0-9._-]+$/', $authority) !== 1)) {
            throw new InvalidArgumentException('Invalid Ads.txt record.');
        }

        return implode(', ', array_filter([$domain, $publisher, $relationship, $authority], fn (string $value): bool => $value !== ''));
    }

    private function adsTxtIdentityKey(DemandAdsTxtRecord $record): string
    {
        return strtolower((string) $this->domains->normalize($record->domain))."\0".trim((string) $record->publisher_account_id);
    }

    private function assertPublisherSite(Publisher $publisher, ?Site $site): void
    {
        if ($site && ($site->publisher_id !== $publisher->id || $site->organization_id !== $publisher->organization_id)) {
            throw ValidationException::withMessages(['site_id' => 'Seller declarations may only be assigned to a website owned by the selected publisher.']);
        }
    }

    /** @param array<string, mixed> $candidate */
    private function assertSellerIdAvailable(SellerDeclaration $current, array $candidate): void
    {
        $matches = SellerDeclaration::withoutGlobalScope('organization')
            ->with(['publisher', 'site.publisher'])
            ->where('seller_id', $candidate['seller_id'])
            ->where('status', 'ACTIVE')
            ->when($current->exists, fn ($query) => $query->whereKeyNot($current->id))
            ->get();
        $candidateFingerprint = $this->sellerFingerprint(
            (string) $candidate['publisher_id'],
            (string) $candidate['seller_type'],
            (string) $candidate['name'],
            (string) $candidate['domain'],
            (bool) $candidate['is_confidential'],
        );

        if ($matches->contains(function (SellerDeclaration $match) use ($candidateFingerprint): bool {
            $publisher = $this->sellerPublisher($match);
            if (! $publisher) {
                return true;
            }

            return $candidateFingerprint !== $this->sellerFingerprint(
                $publisher->id,
                (string) $match->seller_type,
                (string) $match->name,
                (string) $match->domain,
                (bool) $match->is_confidential,
            );
        })) {
            throw ValidationException::withMessages([
                'seller_id' => 'This seller ID already maps to a different entity or public identity.',
            ]);
        }
    }

    private function sellerFingerprint(string $publisherId, string $type, string $name, string $domain, bool $confidential): string
    {
        $publicIdentity = $confidential ? [] : [trim($name), $this->validatedDomain($domain, 'domain')];

        return hash('sha256', json_encode([
            $publisherId, strtoupper($type), $confidential, $publicIdentity,
        ], JSON_THROW_ON_ERROR));
    }

    private function sellerPublisher(SellerDeclaration $declaration): ?Publisher
    {
        if ($declaration->publisher) {
            return $declaration->publisher;
        }
        if ($declaration->site?->publisher) {
            return $declaration->site->publisher;
        }

        return Publisher::withoutGlobalScope('organization')->where('organization_id', $declaration->organization_id)->first();
    }

    /** @return array<string, mixed> */
    private function safeSellerValues(SellerDeclaration $declaration): array
    {
        return [
            'publisher_id' => $declaration->publisher_id,
            'site_id' => $declaration->site_id,
            'seller_id' => $declaration->seller_id,
            'seller_type' => $declaration->seller_type,
            'name' => $declaration->is_confidential ? '[CONFIDENTIAL]' : $declaration->name,
            'domain' => $declaration->is_confidential ? '[CONFIDENTIAL]' : $declaration->domain,
            'is_confidential' => (bool) $declaration->is_confidential,
            'status' => $declaration->status,
            'review_status' => $declaration->review_status,
        ];
    }

    private function validatedDomain(mixed $value, string $key): ?string
    {
        try {
            return $this->domains->normalize(is_scalar($value) ? (string) $value : null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$key => $exception->getMessage()]);
        }
    }

    /** @return array{code: string, severity: string, message: string} */
    private function finding(string $code, string $severity, string $message): array
    {
        return compact('code', 'severity', 'message');
    }

    /** @param array<int, array<string, string>> $findings
     * @return array<int, array<string, string>>
     */
    private function uniqueFindings(array $findings): array
    {
        return collect($findings)->unique(fn (array $finding): string => implode('|', $finding))->values()->all();
    }
}
