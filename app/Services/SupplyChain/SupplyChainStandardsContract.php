<?php

namespace App\Services\SupplyChain;

use App\Enums\PublisherApplicationStatus;
use App\Enums\SellerIdentityScope;
use App\Enums\SellerIdentitySource;
use App\Enums\SellerType;
use App\Enums\SiteManagementRole;
use App\Models\PublisherApplicationDomainClaim;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\SiteServingSetting;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\SupplyChain\Data\CanonicalAdsTxtSource;
use InvalidArgumentException;

final class SupplyChainStandardsContract
{
    public const MANAGER_PRIMARY = 'PRIMARY';
    public const MANAGER_EXCLUSIVE = 'EXCLUSIVE';

    public function __construct(
        private readonly SupplyChainInvariantService $invariants,
        private readonly DomainNormalizer $domains,
        private readonly BidderAdsTxtService $bidderAdsTxt,
        private readonly PlatformAdsTxtService $platformAdsTxt,
        private readonly CanonicalAdsTxtComposer $adsTxtComposer,
    ) {}

    /** @return array{sellers: array, findings: array} */
    public function sellers(): array
    {
        $network = $this->invariants->sellers();
        $findings = collect($network['findings']);
        $sellers = collect($network['sellers']);
        $existingIds = $sellers->pluck('payload.seller_id')->map(fn ($id): string => (string) $id)->all();

        // Disabled HORUS_MANAGED identities may still be public seller account IDs while a
        // Publisher/Site is completing onboarding. Publication eligibility is lifecycle-
        // based; confidentiality only controls whether name/domain are disclosed. Keeping
        // the query restricted to is_confidential=true caused non-confidential sellers to
        // disappear from sellers.json as soon as their public identity was completed.
        $reserved = SellerDeclaration::withoutGlobalScopes()
            ->with(['publisher', 'site', 'applicationDomainClaim.application'])
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('status', 'DISABLED')
            ->orderBy('seller_id')->get()
            ->filter(fn (SellerDeclaration $declaration): bool => ! in_array((string) $declaration->seller_id, $existingIds, true))
            ->filter(fn (SellerDeclaration $declaration): bool => $this->reservedIdentityPublishable($declaration))
            ->map(fn (SellerDeclaration $declaration): array => $this->reservedSeller($declaration));
        $sellers = $sellers->merge($reserved);

        $sellers = $sellers->filter(function (array $seller) use ($findings): bool {
            /** @var SellerDeclaration|null $declaration */
            $declaration = $seller['declaration'] ?? null;
            if (! $declaration?->site_id) {
                return true;
            }
            if ($this->isManagedWebsiteIdentity($declaration) && $this->hasPublisherLevelIdentity($declaration)) {
                return true;
            }
            $findings->push($this->finding(
                'SITE_SPECIFIC_SELLER_ID_UNSUPPORTED', 'ERROR',
                'A site-scoped seller ID has no canonical Publisher legal-entity identity. Only the managed HMS website identity may coexist with the Publisher HMP identity.',
                (string) $declaration->seller_id, $declaration->site_id,
            ));

            return false;
        })->sortBy(fn (array $seller): string => (string) data_get($seller, 'payload.seller_id'))->values()->all();

        return ['sellers' => $sellers, 'findings' => $findings->values()->all()];
    }

    /** @return array{seller: ?array, declarations: array, findings: array} */
    public function sellerForSite(Site $site, ?array $network = null): array
    {
        $network ??= $this->sellers();
        $published = collect($network['sellers'])->keyBy(fn (array $seller): string => (string) data_get($seller, 'payload.seller_id'));
        $findings = $network['findings'];

        $website = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $site->publisher_id)
            ->where('site_id', $site->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('identity_scope', SellerIdentityScope::Website->value)
            ->where('status', 'ACTIVE')
            ->orderBy('seller_id')->get()
            ->filter(fn (SellerDeclaration $seller): bool => $published->has((string) $seller->seller_id))->values();
        if ($website->count() > 1) {
            $findings[] = $this->finding('SITE_SELLER_CONFLICT', 'ERROR', 'The website resolves to multiple active HMS seller IDs; no transaction identity is guessed.', null, $site->id);

            return ['seller' => null, 'declarations' => $website->all(), 'findings' => $this->uniqueFindings($findings)];
        }
        if ($website->count() === 1) {
            $sellerId = (string) $website->first()->seller_id;

            return ['seller' => $published->get($sellerId), 'declarations' => $website->all(), 'findings' => $this->uniqueFindings($findings)];
        }

        // Backward-compatible fallback for pre-Task-39 Sites: use the one legacy/HMP
        // seller identity until a Website HMS identity has been reviewed and activated.
        $legacy = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $site->publisher_id)
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($site): void {
                $query->whereNull('site_id')->orWhere(function ($siteScope) use ($site): void {
                    $siteScope->where('site_id', $site->id)
                        ->where(function ($identity): void {
                            $identity->where('identity_source', '!=', SellerIdentitySource::HorusManaged->value)
                                ->orWhere('identity_scope', '!=', SellerIdentityScope::Website->value);
                        });
                });
            })
            ->orderBy('seller_id')->get()
            ->filter(fn (SellerDeclaration $seller): bool => $published->has((string) $seller->seller_id))->values();
        $ids = $legacy->pluck('seller_id')->map(fn ($id): string => (string) $id)->unique()->values();
        if ($ids->count() > 1) {
            $findings[] = $this->finding('SITE_SELLER_CONFLICT', 'ERROR', 'The website resolves to multiple active legacy seller IDs; no account-specific identity is guessed.', null, $site->id);
        } elseif ($ids->isEmpty()) {
            $findings[] = $this->finding('SITE_SELLER_MISSING', 'WARNING', 'No unambiguous active seller identity is available for this website.', null, $site->id);
        }

        return [
            'seller' => $ids->count() === 1 ? $published->get($ids->first()) : null,
            'declarations' => $legacy->all(),
            'findings' => $this->uniqueFindings($findings),
        ];
    }

    /** @return array{complete: int, ver: string, nodes: array, findings: array} */
    public function schainForSite(Site $site, ?array $network = null): array
    {
        $selection = $this->sellerForSite($site, $network);
        $findings = $selection['findings'];
        $managerDomain = null;
        try {
            $managerDomain = $this->horusAdvertisingSystemDomain();
        } catch (InvalidArgumentException) {
            $findings[] = $this->finding('MANAGER_DOMAIN_INVALID', 'ERROR', 'The configured Horus advertising-system domain is invalid.', null, $site->id);
        }
        if (! $selection['seller'] || ! $managerDomain) {
            return ['complete' => 0, 'ver' => '1.0', 'nodes' => [], 'findings' => $this->uniqueFindings($findings)];
        }

        $sellerId = (string) data_get($selection['seller'], 'payload.seller_id');
        if (strlen($sellerId) > 64) {
            $findings[] = $this->finding('SCHAIN_SELLER_ID_TOO_LONG', 'ERROR', 'The selected seller ID exceeds the SupplyChain Object 64-character limit.', $sellerId, $site->id);

            return ['complete' => 0, 'ver' => '1.0', 'nodes' => [], 'findings' => $this->uniqueFindings($findings)];
        }
        $type = SellerType::from((string) data_get($selection['seller'], 'payload.seller_type'));
        $complete = $type->ownsInventory() ? 1 : 0;
        if ($complete === 0) {
            $findings[] = $this->finding('SCHAIN_UPSTREAM_IDENTITY_REQUIRED', 'WARNING', 'The selected seller is an intermediary; an upstream inventory-owner node is required before schain can be complete.', $sellerId, $site->id);
        }

        // HMP and HMS are two account identifiers for the same paid entity, never two hops.
        // For a Task-39 website seller, sellerForSite selects HMS and exactly one Horus node is emitted.
        return [
            'complete' => $complete,
            'ver' => '1.0',
            'nodes' => [['asi' => $managerDomain, 'sid' => $sellerId, 'hp' => 1]],
            'findings' => $this->uniqueFindings($findings),
        ];
    }

    /** @return array{records: array, entries: array, lines: array, findings: array} */
    public function adsTxtForSite(Site $site, ?array $network = null): array
    {
        $network ??= $this->sellers();
        $raw = $this->invariants->adsTxtForSite($site, $network);
        $findings = collect($raw['findings'])->reject(
            fn (array $finding): bool => in_array($finding['code'] ?? null, ['ADS_TXT_RELATIONSHIP_CONFLICT', 'DUPLICATE_ADS_TXT_RECORD', 'SITE_SELLER_CONFLICT'], true),
        )->values()->all();
        $sources = [];

        foreach (($raw['entries'] ?? []) as $entry) {
            if (($entry['source_type'] ?? null) === 'SELLER_DECLARATION' || ! $entry['record']) {
                continue;
            }
            $record = $entry['record'];
            $sources[] = new CanonicalAdsTxtSource(
                'DEMAND_RECORD', (string) $record->id, (string) $record->domain,
                (string) $record->publisher_account_id, strtoupper((string) $record->relationship),
                $record->certification_authority_id ?: null, (string) $entry['line'],
                '4|'.($record->site_id ? '1' : '2').'|'.$record->demand_account_id.'|'.$record->id,
                $record, null,
                ['scope' => $record->site_id ? 'WEBSITE' : 'DEMAND_ACCOUNT_GLOBAL', 'demand_account_id' => $record->demand_account_id],
            );
        }

        foreach ($this->platformAdsTxt->sourcesForSite($site) as $source) {
            $sources[] = $source;
        }

        $bidder = $this->bidderAdsTxt->entriesForSite($site);
        $findings = array_merge($findings, $bidder['findings']);
        foreach ($bidder['entries'] as $entry) {
            $record = $entry['record'];
            $sources[] = new CanonicalAdsTxtSource(
                'BIDDER_RECORD', (string) $record->id, (string) $record->advertising_system_domain,
                (string) $record->publisher_account_id, strtoupper((string) $record->relationship),
                $record->certification_authority_id ?: null, (string) $entry['line'],
                '3|'.($record->site_id ? '1' : '2').'|'.$record->bidder_account_id.'|'.$record->id,
                $record, null,
                ['scope' => $record->site_id ? 'WEBSITE' : 'BIDDER_ACCOUNT_GLOBAL', 'bidder_account_id' => $record->bidder_account_id],
            );
        }

        $published = collect($network['sellers'])->keyBy(fn (array $seller): string => (string) data_get($seller, 'payload.seller_id'));
        $addedIds = collect();
        $managed = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $site->publisher_id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($site): void {
                $query->where('identity_scope', SellerIdentityScope::Publisher->value)
                    ->orWhere(function ($website) use ($site): void {
                        $website->where('identity_scope', SellerIdentityScope::Website->value)->where('site_id', $site->id);
                    });
            })
            ->orderBy('identity_scope')->orderBy('seller_id')->get();

        foreach ($managed as $declaration) {
            if (! $published->has((string) $declaration->seller_id)) {
                continue;
            }
            $this->appendSellerAdsTxtSource($sources, $findings, $declaration, $site, $addedIds);
        }

        // Preserve legacy/manual seller authorization when there is no managed identity
        // for the selected transaction seller.
        $selection = $this->sellerForSite($site, $network);
        $findings = array_merge($findings, $selection['findings']);
        if ($selection['seller']) {
            /** @var SellerDeclaration $selected */
            $selected = $selection['seller']['declaration'];
            if (! $addedIds->contains((string) $selected->seller_id)) {
                $this->appendSellerAdsTxtSource($sources, $findings, $selected, $site, $addedIds);
            }
        }

        return $this->adsTxtComposer->compose($sources, $findings);
    }

    public function ownerDomainForSite(Site $site): ?string
    {
        $site->loadMissing('publisher');
        $domain = trim((string) $site->publisher?->business_domain);

        return $domain === '' ? null : $this->domains->normalize($domain);
    }

    /** @return array{domain: string, relationship: string, country: ?string, line: string, role: string}|null */
    public function managerDirectiveForSite(Site $site): ?array
    {
        $settings = SiteServingSetting::withoutGlobalScopes()->where('site_id', $site->id)->first();
        if (! $settings) {
            return null;
        }

        $rawRole = strtoupper(trim((string) $settings->getRawOriginal('monetization_manager_role')));
        $role = SiteManagementRole::tryFrom($rawRole === '' ? SiteManagementRole::None->value : $rawRole);
        if (! $role) {
            throw new InvalidArgumentException('The per-site monetization-manager role is invalid.');
        }

        $configuredDomain = trim((string) $settings->monetization_manager_domain);
        $configuredRelationship = strtoupper(trim((string) $settings->monetization_manager_relationship));
        $country = strtoupper(trim((string) $settings->monetization_manager_country));

        if ($role === SiteManagementRole::None) {
            if ($configuredDomain !== '' || $configuredRelationship !== '' || $country !== '') {
                throw new InvalidArgumentException('MANAGERDOMAIN fields are configured without an authorized per-site management role.');
            }

            return null;
        }

        $domain = $this->horusAdvertisingSystemDomain();
        if ($configuredDomain !== '' && ! $this->domains->same($configuredDomain, $domain)) {
            throw new InvalidArgumentException('An approved Horus management role may only emit the canonical Horus manager domain.');
        }

        $relationship = (string) $role->relationship();
        if ($configuredRelationship !== '' && $configuredRelationship !== $relationship) {
            throw new InvalidArgumentException('The monetization-manager relationship conflicts with the approved management role.');
        }

        if ($role->isCountryScoped()) {
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new InvalidArgumentException('A country-scoped MANAGERDOMAIN role requires an ISO 3166-1 alpha-2 country code.');
            }
        } elseif ($country !== '') {
            throw new InvalidArgumentException('A global MANAGERDOMAIN role cannot carry a country code.');
        }

        return [
            'domain' => $domain,
            'relationship' => $relationship,
            'country' => $role->isCountryScoped() ? $country : null,
            'line' => 'MANAGERDOMAIN='.$domain.($role->isCountryScoped() ? ', '.$country : ''),
            'role' => $role->value,
        ];
    }

    /** @return list<string> */
    public function optionalDirectivesForSite(Site $site): array
    {
        $directives = [];
        $contact = trim((string) config('ads-txt.contact'));
        if ((bool) config('ads-txt.contact_reviewed', false) && $contact !== '') {
            $directives[] = 'CONTACT='.$contact;
        }
        foreach ((array) config('ads-txt.inventory_partner_domains', []) as $domain) {
            $directives[] = 'INVENTORYPARTNERDOMAIN='.$this->domains->normalize((string) $domain);
        }
        foreach ((array) config('ads-txt.subdomains', []) as $domain) {
            $directives[] = 'SUBDOMAIN='.$this->domains->normalize((string) $domain);
        }

        return array_values(array_unique($directives));
    }

    public function horusAdvertisingSystemDomain(): string
    {
        return (string) $this->domains->normalize((string) config('supply-chain.manager_domain', 'horusmedia.net'));
    }

    /** @param array<int, CanonicalAdsTxtSource> $sources @param array<int, array<string, mixed>> $findings */
    private function appendSellerAdsTxtSource(array &$sources, array &$findings, SellerDeclaration $declaration, Site $site, $addedIds): void
    {
        $relationship = strtoupper(trim((string) $declaration->ads_txt_relationship));
        if (! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            $findings[] = $this->finding(
                $relationship === '' ? 'ADS_TXT_RELATIONSHIP_UNCONFIGURED' : 'ADS_TXT_RELATIONSHIP_INVALID',
                $relationship === '' ? 'WARNING' : 'ERROR',
                $relationship === '' ? 'No Horus ads.txt relationship is emitted until DIRECT or RESELLER is explicitly configured.' : 'The Horus seller ads.txt relationship must be DIRECT or RESELLER.',
                (string) $declaration->seller_id, $site->id,
            );

            return;
        }
        $system = $this->horusAdvertisingSystemDomain();
        $sellerId = (string) $declaration->seller_id;
        $scope = $declaration->identity_scope instanceof SellerIdentityScope
            ? $declaration->identity_scope->value : (string) $declaration->identity_scope;
        $sources[] = new CanonicalAdsTxtSource(
            'SELLER_DECLARATION', (string) $declaration->id, $system, $sellerId, $relationship,
            null, $system.', '.$sellerId.', '.$relationship,
            '0|'.($scope === SellerIdentityScope::Publisher->value ? '1' : '2').'|'.$sellerId,
            null, $declaration, ['scope' => $scope],
        );
        $addedIds->push($sellerId);
    }

    private function isManagedWebsiteIdentity(SellerDeclaration $declaration): bool
    {
        $source = $declaration->identity_source instanceof SellerIdentitySource
            ? $declaration->identity_source : SellerIdentitySource::tryFrom((string) $declaration->identity_source);
        $scope = $declaration->identity_scope instanceof SellerIdentityScope
            ? $declaration->identity_scope : SellerIdentityScope::tryFrom((string) $declaration->identity_scope);

        return $source === SellerIdentitySource::HorusManaged && $scope === SellerIdentityScope::Website;
    }

    private function hasPublisherLevelIdentity(SellerDeclaration $declaration): bool
    {
        $publisherIdentity = SellerDeclaration::withoutGlobalScopes()
            ->with(['publisher', 'site', 'applicationDomainClaim.application'])
            ->where('publisher_id', $declaration->publisher_id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('identity_scope', SellerIdentityScope::Publisher->value)
            ->first();
        if (! $publisherIdentity) {
            return false;
        }

        return (string) $publisherIdentity->status->value === 'ACTIVE'
            || ((string) $publisherIdentity->status->value === 'DISABLED' && $this->reservedIdentityPublishable($publisherIdentity));
    }

    /** @return array{declaration: SellerDeclaration, publisher_id: mixed, payload: array<string, mixed>, fingerprint: string} */
    private function reservedSeller(SellerDeclaration $declaration): array
    {
        $publisher = $declaration->publisher;
        $confidential = $this->effectiveConfidentiality($declaration);
        $name = trim((string) ($declaration->name ?: $publisher?->legal_name ?: $publisher?->display_name));
        $domain = trim((string) ($declaration->domain ?: $publisher?->business_domain ?: $declaration->site?->primary_domain));
        try {
            $domain = $domain === '' ? '' : (string) $this->domains->normalize($domain);
        } catch (InvalidArgumentException) {
            $domain = '';
        }

        // Never emit an invalid non-confidential Seller object. If historic data has not
        // yet been completed, retain confidentiality until the identity can be repaired.
        if ($name === '' || $domain === '') {
            $confidential = true;
        }
        $payload = [
            'seller_id' => (string) $declaration->seller_id,
            'seller_type' => SellerType::Publisher->value,
            'name' => $confidential ? null : $name,
            'domain' => $confidential ? null : $domain,
            'is_confidential' => $confidential ? 1 : 0,
        ];

        return [
            'declaration' => $declaration,
            'publisher_id' => $declaration->publisher_id,
            'payload' => $payload,
            'fingerprint' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function effectiveConfidentiality(SellerDeclaration $declaration): bool
    {
        if (! (bool) $declaration->is_confidential) {
            return false;
        }

        // Explicit Horus-admin confidentiality review always wins. Legacy/default
        // pre-approval confidentiality is automatically lifted once the Publisher is
        // active and has a public business identity to disclose.
        $review = data_get($declaration->metadata, 'confidentiality_review');
        if (is_array($review) && filled($review['reviewed_at'] ?? null) && filled($review['reviewed_by'] ?? null)) {
            return true;
        }
        $publisher = $declaration->publisher;
        $publisherStatus = strtoupper((string) $publisher?->getRawOriginal('status'));

        return $publisherStatus !== 'ACTIVE' || blank($publisher?->business_domain);
    }

    private function reservedIdentityPublishable(SellerDeclaration $declaration): bool
    {
        $source = $declaration->identity_source instanceof SellerIdentitySource
            ? $declaration->identity_source : SellerIdentitySource::tryFrom((string) $declaration->identity_source);
        if ($source !== SellerIdentitySource::HorusManaged || strtoupper((string) $declaration->status->value) !== 'DISABLED') {
            return false;
        }

        // Preserve the verified legacy application-claim publication path.
        $terminal = [PublisherApplicationStatus::Rejected->value, PublisherApplicationStatus::Withdrawn->value];
        $scope = $declaration->identity_scope instanceof SellerIdentityScope
            ? $declaration->identity_scope : SellerIdentityScope::tryFrom((string) $declaration->identity_scope);
        if ($scope === SellerIdentityScope::Website && $declaration->applicationDomainClaim) {
            $claim = $declaration->applicationDomainClaim;
            if ($claim->claim_status === 'CLAIMED'
                && $claim->verification_status === 'VERIFIED'
                && $claim->application
                && ! in_array($claim->application->status->value, $terminal, true)) {
                return true;
            }
        }
        if ($scope === SellerIdentityScope::Publisher && PublisherApplicationDomainClaim::query()
            ->where('publisher_seller_declaration_id', $declaration->id)
            ->where('claim_status', 'CLAIMED')
            ->where('verification_status', 'VERIFIED')
            ->whereHas('application', fn ($query) => $query->whereNotIn('status', $terminal))
            ->exists()) {
            return true;
        }

        // Express onboarding has no application-domain claim. Once the Publisher account
        // is active and a real Site owns the HMS, both HMP and HMS must remain discoverable
        // in sellers.json while website review/ads.txt verification is still pending.
        $publisher = $declaration->publisher;
        if (! $publisher || strtoupper((string) $publisher->getRawOriginal('status')) !== 'ACTIVE') {
            return false;
        }
        $nonTerminalSiteStatuses = ['DRAFT', 'PENDING_VERIFICATION', 'PENDING_REVIEW', 'APPROVED', 'ACTIVE', 'SUSPENDED'];
        if ($scope === SellerIdentityScope::Website) {
            $site = $declaration->site_id
                ? ($declaration->site ?: Site::withoutGlobalScopes()->find($declaration->site_id))
                : null;

            return $site !== null
                && (string) $site->publisher_id === (string) $declaration->publisher_id
                && in_array(strtoupper((string) $site->getRawOriginal('status')), $nonTerminalSiteStatuses, true);
        }
        if ($scope === SellerIdentityScope::Publisher) {
            $siteIds = SellerDeclaration::withoutGlobalScopes()
                ->where('publisher_id', $declaration->publisher_id)
                ->where('identity_source', SellerIdentitySource::HorusManaged->value)
                ->where('identity_scope', SellerIdentityScope::Website->value)
                ->whereNotNull('site_id')
                ->pluck('site_id');

            return $siteIds->isNotEmpty() && Site::withoutGlobalScopes()
                ->where('publisher_id', $declaration->publisher_id)
                ->whereIn('id', $siteIds)
                ->whereIn('status', $nonTerminalSiteStatuses)
                ->exists();
        }

        return false;
    }

    /** @return array<string, string|null> */
    private function finding(string $code, string $severity, string $message, ?string $sellerId = null, ?string $siteId = null): array
    {
        return array_filter(['code' => $code, 'severity' => $severity, 'message' => $message, 'seller_id' => $sellerId, 'site_id' => $siteId], fn ($value) => $value !== null);
    }

    /** @param array<int, array<string, mixed>> $findings @return array<int, array<string, mixed>> */
    private function uniqueFindings(array $findings): array
    {
        return collect($findings)->unique(fn (array $finding): string => implode('|', array_map('strval', $finding)))->values()->all();
    }
}
