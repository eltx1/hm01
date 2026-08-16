<?php

namespace App\Services\SupplyChain;

use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\SiteServingSetting;
use App\Services\Prebid\BidderAdsTxtService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class SupplyChainStandardsContract
{
    public const MANAGER_PRIMARY = 'PRIMARY';
    public const MANAGER_EXCLUSIVE = 'EXCLUSIVE';

    public function __construct(
        private readonly SupplyChainInvariantService $invariants,
        private readonly DomainNormalizer $domains,
        private readonly BidderAdsTxtService $bidderAdsTxt,
    ) {}

    /** @return array{sellers: array, findings: array} */
    public function sellers(): array
    {
        $network = $this->invariants->sellers();
        $findings = collect($network['findings']);
        $sellers = collect($network['sellers'])->filter(function (array $seller) use ($findings): bool {
            /** @var SellerDeclaration|null $declaration */
            $declaration = $seller['declaration'] ?? null;
            if (! $declaration?->site_id) {
                return true;
            }
            if ($this->hasPublisherLevelIdentity($declaration)) {
                return true;
            }

            $findings->push($this->finding(
                'SITE_SPECIFIC_SELLER_ID_UNSUPPORTED',
                'ERROR',
                'A site-scoped Horus seller ID has no publisher-level legal-entity identity. Site existence must not create a distinct seller identity.',
                $declaration->seller_id,
                $declaration->site_id,
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
        );
        $entries = collect($raw['entries'] ?? [])->reject(
            fn (array $entry): bool => ($entry['source_type'] ?? null) === 'SELLER_DECLARATION',
        )->values();

        $bidder = $this->bidderAdsTxt->entriesForSite($site);
        $entries = $entries->concat($bidder['entries']);
        $findings = $findings->merge($bidder['findings']);

        $selection = $this->sellerForSite($site, $network);
        $findings = $findings->merge($selection['findings']);
        $selected = $selection['seller'];
        if ($selected) {
            /** @var SellerDeclaration $declaration */
            $declaration = $selected['declaration'];
            $relationship = strtoupper(trim((string) $declaration->ads_txt_relationship));
            if (in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
                $sellerId = (string) data_get($selected, 'payload.seller_id');
                $system = $this->horusAdvertisingSystemDomain();
                $entries->push([
                    'record' => null,
                    'declaration' => $declaration,
                    'source_type' => 'SELLER_DECLARATION',
                    'line' => $system.', '.$sellerId.', '.$relationship,
                    'key' => strtolower($system)."\0".$sellerId,
                    'sort_key' => '0|'.$sellerId,
                ]);
            } elseif ($relationship !== '') {
                $findings->push($this->finding(
                    'ADS_TXT_RELATIONSHIP_INVALID',
                    'ERROR',
                    'The Horus seller ads.txt relationship must be explicitly DIRECT or RESELLER.',
                    (string) $declaration->seller_id,
                    $site->id,
                ));
            } else {
                $findings->push($this->finding(
                    'ADS_TXT_RELATIONSHIP_UNCONFIGURED',
                    'WARNING',
                    'No Horus ads.txt relationship is emitted until DIRECT or RESELLER is explicitly configured.',
                    (string) $declaration->seller_id,
                    $site->id,
                ));
            }
        }

        $resolved = collect();
        foreach ($entries->groupBy('key') as $key => $group) {
            $lines = $group->pluck('line')->unique()->values();
            if ($lines->count() > 1) {
                $findings->push([
                    'code' => 'ADS_TXT_RELATIONSHIP_CONFLICT',
                    'severity' => 'ERROR',
                    'message' => 'The same advertising-system account has conflicting explicit ads.txt relationships.',
                    'identity' => base64_encode((string) $key),
                ]);
                continue;
            }
            $winner = $group->sortBy('sort_key')->first();
            if ($group->count() > 1) {
                $findings->push([
                    'code' => 'DUPLICATE_ADS_TXT_RECORD',
                    'severity' => 'WARNING',
                    'message' => 'Equivalent explicit ads.txt records collapse to one deterministic line.',
                ]);
            }
            $resolved->push($winner);
        }
        $resolved = $resolved->sortBy(fn (array $entry): string => strtolower((string) $entry['line']))->values();

        return [
            'records' => $resolved->pluck('record')->filter()->values()->all(),
            'entries' => $resolved->all(),
            'lines' => $resolved->pluck('line')->values()->all(),
            'findings' => $findings->unique(fn (array $finding): string => implode('|', array_map('strval', $finding)))->values()->all(),
        ];
    }

    public function ownerDomainForSite(Site $site): ?string
    {
        $site->loadMissing('publisher');
        $domain = trim((string) $site->publisher?->business_domain);
        if ($domain === '') {
            return null;
        }

        return $this->domains->normalize($domain);
    }

    /** @return array{domain: string, relationship: string, country: ?string, line: string}|null */
    public function managerDirectiveForSite(Site $site): ?array
    {
        $settings = SiteServingSetting::withoutGlobalScopes()->where('site_id', $site->id)->first();
        $domain = trim((string) $settings?->monetization_manager_domain);
        $relationship = strtoupper(trim((string) $settings?->monetization_manager_relationship));
        $country = strtoupper(trim((string) $settings?->monetization_manager_country));

        if ($domain === '' && $relationship === '' && $country === '') {
            return null;
        }
        if (! in_array($relationship, [self::MANAGER_PRIMARY, self::MANAGER_EXCLUSIVE], true)) {
            throw new InvalidArgumentException('MANAGERDOMAIN requires an explicit PRIMARY or EXCLUSIVE monetization-manager relationship.');
        }
        if ($domain === '') {
            throw new InvalidArgumentException('MANAGERDOMAIN requires an explicit manager business domain.');
        }
        $domain = $this->domains->normalize($domain);
        if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('MANAGERDOMAIN country must be an ISO 3166-1 alpha-2 code.');
        }

        return [
            'domain' => $domain,
            'relationship' => $relationship,
            'country' => $country !== '' ? $country : null,
            'line' => 'MANAGERDOMAIN='.$domain.($country !== '' ? ', '.$country : ''),
        ];
    }

    public function horusAdvertisingSystemDomain(): string
    {
        return (string) $this->domains->normalize((string) config('supply-chain.manager_domain', 'horusmedia.net'));
    }

    private function hasPublisherLevelIdentity(SellerDeclaration $declaration): bool
    {
        return SellerDeclaration::withoutGlobalScope('organization')
            ->where('publisher_id', $declaration->publisher_id)
            ->whereNull('site_id')
            ->where('seller_id', $declaration->seller_id)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    /** @return array<string, string|null> */
    private function finding(string $code, string $severity, string $message, ?string $sellerId = null, ?string $siteId = null): array
    {
        return array_filter([
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'seller_id' => $sellerId,
            'site_id' => $siteId,
        ], fn ($value) => $value !== null);
    }
}
