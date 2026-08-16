<?php

namespace App\Services\SupplyChain;

use App\Enums\SiteManagementRole;
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
        $sellers = collect($network['sellers'])->filter(function (array $seller) use ($findings): bool {
            /** @var SellerDeclaration|null $declaration */
            $declaration = $seller['declaration'] ?? null;
            if (! $declaration?->site_id || $this->hasPublisherLevelIdentity($declaration)) {
                return true;
            }
            $findings->push($this->finding(
                'SITE_SPECIFIC_SELLER_ID_UNSUPPORTED', 'ERROR',
                'A site-scoped Horus seller ID has no publisher-level legal-entity identity. Site existence must not create a distinct seller identity.',
                $declaration->seller_id, $declaration->site_id,
            ));
            return false;
        })->values()->all();

        return ['sellers' => $sellers, 'findings' => $findings->values()->all()];
    }

    /** @return array{seller: ?array, findings: array} */
    public function sellerForSite(Site $site, ?array $network = null): array
    {
        $network ??= $this->sellers();
        return $this->invariants->sellerForSite($site, $network);
    }

    /** @return array{complete: int, ver: string, nodes: array, findings: array} */
    public function schainForSite(Site $site, ?array $network = null): array
    {
        $network ??= $this->sellers();
        return $this->invariants->schainForSite($site, $network);
    }

    /** @return array{records: array, entries: array, lines: array, findings: array} */
    public function adsTxtForSite(Site $site, ?array $network = null): array
    {
        $network ??= $this->sellers();
        $raw = $this->invariants->adsTxtForSite($site, $network);
        $findings = collect($raw['findings'])->reject(
            fn (array $finding): bool => in_array($finding['code'] ?? null, ['ADS_TXT_RELATIONSHIP_CONFLICT', 'DUPLICATE_ADS_TXT_RECORD'], true),
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

        $selection = $this->sellerForSite($site, $network);
        $findings = array_merge($findings, $selection['findings']);
        $selected = $selection['seller'];
        if ($selected) {
            /** @var SellerDeclaration $declaration */
            $declaration = $selected['declaration'];
            $relationship = strtoupper(trim((string) $declaration->ads_txt_relationship));
            if (in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
                $sellerId = (string) data_get($selected, 'payload.seller_id');
                $system = $this->horusAdvertisingSystemDomain();
                $sources[] = new CanonicalAdsTxtSource(
                    'SELLER_DECLARATION', (string) $declaration->id, $system, $sellerId, $relationship,
                    null, $system.', '.$sellerId.', '.$relationship, '0|'.$sellerId,
                    null, $declaration, ['scope' => $declaration->site_id ? 'WEBSITE' : 'PUBLISHER_GLOBAL'],
                );
            } elseif ($relationship !== '') {
                $findings[] = $this->finding('ADS_TXT_RELATIONSHIP_INVALID', 'ERROR', 'The Horus seller ads.txt relationship must be explicitly DIRECT or RESELLER.', (string) $declaration->seller_id, $site->id);
            } else {
                $findings[] = $this->finding('ADS_TXT_RELATIONSHIP_UNCONFIGURED', 'WARNING', 'No Horus ads.txt relationship is emitted until DIRECT or RESELLER is explicitly configured.', (string) $declaration->seller_id, $site->id);
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

    private function hasPublisherLevelIdentity(SellerDeclaration $declaration): bool
    {
        return SellerDeclaration::withoutGlobalScope('organization')->where('publisher_id', $declaration->publisher_id)
            ->whereNull('site_id')->where('seller_id', $declaration->seller_id)->where('status', 'ACTIVE')->exists();
    }

    /** @return array<string, string|null> */
    private function finding(string $code, string $severity, string $message, ?string $sellerId = null, ?string $siteId = null): array
    {
        return array_filter(['code' => $code, 'severity' => $severity, 'message' => $message, 'seller_id' => $sellerId, 'site_id' => $siteId], fn ($value) => $value !== null);
    }
}
