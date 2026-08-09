<?php

namespace App\Services\Compliance;

use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerType;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Services\StaticDelivery\CanonicalJson;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class SupplyChainComplianceService
{
    public function __construct(
        private readonly SupplyChainInvariantService $invariants,
        private readonly SupplyChainArtifactBuilder $artifacts,
        private readonly CanonicalJson $json,
    ) {}

    /** @return array<string, mixed> */
    public function networkOverview(): array
    {
        $network = $this->networkWithStoredDeclarationFindings();
        $siteSummaries = Site::withoutGlobalScope('organization')->with('publisher')->orderBy('primary_domain')->get()
            ->map(fn (Site $site): array => $this->siteOverview($site, $network));
        $findings = collect($network['findings'])
            ->merge($siteSummaries->pluck('findings')->flatten(1))
            ->unique(fn (array $finding): string => implode('|', array_map('strval', $finding)))
            ->values();

        return [
            'network' => $network,
            'payload' => $this->artifacts->sellersJsonPayload(),
            'artifact' => $this->artifacts->sellersJson(),
            'site_summaries' => $siteSummaries,
            'findings' => $findings->all(),
            'healthy' => ! $findings->contains(fn (array $finding): bool => $finding['severity'] === 'ERROR'),
        ];
    }

    /** @return array<string, mixed> */
    public function declarationOverview(SellerDeclaration $declaration, ?array $network = null): array
    {
        $declaration->loadMissing(['publisher.sites', 'site', 'reviewer']);
        $network = $this->networkWithStoredDeclarationFindings($network);
        $published = collect($network['sellers'])->first(
            fn (array $seller): bool => (string) data_get($seller, 'payload.seller_id') === (string) $declaration->seller_id,
        );
        $sites = $declaration->site
            ? collect([$declaration->site])
            : ($declaration->publisher?->sites ?? collect());
        $siteSummaries = $sites->sortBy('primary_domain')->map(
            fn (Site $site): array => $this->siteOverview($site, $network),
        )->values();
        $status = $declaration->status instanceof SellerDeclarationStatus
            ? $declaration->status
            : SellerDeclarationStatus::from((string) $declaration->status);
        $review = $declaration->review_status instanceof SupplyChainReviewStatus
            ? $declaration->review_status
            : SupplyChainReviewStatus::from((string) $declaration->review_status);
        $findings = $siteSummaries->pluck('findings')->flatten(1)->filter(
            fn (array $finding): bool => ! isset($finding['seller_id']) || $finding['seller_id'] === $declaration->seller_id,
        )->merge(collect($network['declaration_findings'])->where('seller_id', (string) $declaration->seller_id))->values();
        if ($status === SellerDeclarationStatus::Active && ! $published) {
            $findings->push($this->finding(
                'ACTIVE_SELLER_NOT_PUBLISHED',
                'ERROR',
                'The active declaration is excluded from sellers.json because its canonical identity is incomplete or conflicting.',
                $declaration,
            ));
        }
        if ($review !== SupplyChainReviewStatus::Verified) {
            $findings->push($this->finding(
                'SELLER_REVIEW_REQUIRED',
                'WARNING',
                'The seller identity requires an Admin review before a new activation.',
                $declaration,
            ));
        }

        $health = match (true) {
            $status === SellerDeclarationStatus::Disabled => 'INACTIVE',
            $findings->contains(fn (array $finding): bool => $finding['severity'] === 'ERROR') => 'CONFLICT',
            $findings->isNotEmpty() => 'REVIEW_REQUIRED',
            default => 'HEALTHY',
        };

        return [
            'declaration' => $declaration,
            'status' => $status,
            'review_status' => $review,
            'health' => $health,
            'published' => (bool) $published,
            'json_fragment' => $published
                ? $this->json->encode(array_filter($published['payload'], fn ($value) => $value !== null))
                : null,
            'sites' => $siteSummaries,
            'ads_txt_health' => $this->aggregateHealth($siteSummaries, 'ads_txt_health'),
            'schain_health' => $this->aggregateHealth($siteSummaries, 'schain_health'),
            'findings' => $findings->unique(fn (array $finding): string => implode('|', array_map('strval', $finding)))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function siteOverview(Site $site, ?array $network = null): array
    {
        $network ??= $this->invariants->sellers();
        $selection = $this->invariants->sellerForSite($site, $network);
        $adsTxt = $this->invariants->adsTxtForSite($site, $network);
        $schain = $this->invariants->schainForSite($site, $network);
        $published = collect($network['sellers'])->keyBy(fn (array $seller): string => (string) data_get($seller, 'payload.seller_id'));
        $findings = collect(array_merge($selection['findings'], $adsTxt['findings'], $schain['findings']));
        $managerDomain = null;
        try {
            $managerDomain = $this->invariants->managerDomain();
        } catch (InvalidArgumentException) {
            $findings->push($this->finding('MANAGER_DOMAIN_INVALID', 'ERROR', 'The configured Horus manager domain is invalid.'));
        }

        $managerRecords = collect($adsTxt['lines'])->map(fn (string $line): array => array_map('trim', explode(',', $line)))
            ->filter(fn (array $fields): bool => count($fields) >= 3 && strtolower($fields[0]) === $managerDomain)
            ->values();
        foreach ($managerRecords as $fields) {
            $sellerId = $fields[1];
            $seller = $published->get($sellerId);
            if (! $seller) {
                $findings->push($this->finding(
                    'ADS_TXT_UNKNOWN_HORUS_SELLER',
                    'ERROR',
                    'Ads.txt authorizes Horus seller '.$sellerId.', but that seller ID is absent from valid sellers.json output.',
                    sellerId: $sellerId,
                    site: $site,
                ));

                continue;
            }
            if ((string) $seller['publisher_id'] !== (string) $site->publisher_id) {
                $findings->push($this->finding(
                    'ADS_TXT_SELLER_ENTITY_MISMATCH',
                    'ERROR',
                    'Ads.txt authorizes a Horus seller ID belonging to another paid entity.',
                    sellerId: $sellerId,
                    site: $site,
                ));
            }
            $type = SellerType::from((string) data_get($seller, 'payload.seller_type'));
            $relationship = strtoupper($fields[2]);
            if ($type !== SellerType::Both && $relationship !== $type->expectedAdsTxtRelationship()) {
                $findings->push($this->finding(
                    'ADS_TXT_SELLER_TYPE_MISMATCH',
                    'ERROR',
                    'The Ads.txt relationship does not match the seller type declared by Horus.',
                    sellerId: $sellerId,
                    site: $site,
                ));
            }
        }

        foreach ($schain['nodes'] as $node) {
            if ($managerDomain && strtolower((string) $node['asi']) === $managerDomain && ! $published->has((string) $node['sid'])) {
                $findings->push($this->finding(
                    'SCHAIN_UNKNOWN_SELLER',
                    'ERROR',
                    'The generated schain references a seller ID that is absent from sellers.json.',
                    sellerId: (string) $node['sid'],
                    site: $site,
                ));
            }
        }

        $selectedSellerId = $selection['seller'] ? (string) data_get($selection['seller'], 'payload.seller_id') : null;
        $hasAdsAuthorization = $selectedSellerId !== null && $managerRecords->contains(
            fn (array $fields): bool => (string) $fields[1] === $selectedSellerId,
        );
        $hasSchainNode = $selectedSellerId !== null && collect($schain['nodes'])->contains(
            fn (array $node): bool => (string) $node['asi'] === $managerDomain && (string) $node['sid'] === $selectedSellerId,
        );
        if ($selectedSellerId && ! $hasAdsAuthorization) {
            $findings->push($this->finding(
                'ADS_TXT_SELLER_AUTHORIZATION_MISSING',
                'ERROR',
                'The selected Horus seller ID is not present in this website’s canonical Ads.txt output.',
                sellerId: $selectedSellerId,
                site: $site,
            ));
        }
        if ($selectedSellerId && ! $hasSchainNode) {
            $findings->push($this->finding(
                'SCHAIN_SELLER_REFERENCE_MISSING',
                'ERROR',
                'The selected Horus seller ID is not represented by a valid schain node.',
                sellerId: $selectedSellerId,
                site: $site,
            ));
        }

        $findings = $findings->unique(fn (array $finding): string => implode('|', array_map('strval', $finding)))->values();

        return [
            'site' => $site,
            'seller_id' => $selectedSellerId,
            'ads_txt_health' => $selectedSellerId ? ($hasAdsAuthorization ? 'HEALTHY' : 'CONFLICT') : 'NOT_CONFIGURED',
            'schain_health' => $selectedSellerId ? ($hasSchainNode ? ($schain['complete'] === 1 ? 'HEALTHY' : 'PARTIAL') : 'CONFLICT') : 'NOT_CONFIGURED',
            'schain' => ['complete' => $schain['complete'], 'ver' => $schain['ver'], 'nodes' => $schain['nodes']],
            'findings' => $findings->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function publisherOverview(Publisher $publisher): array
    {
        $network = $this->networkWithStoredDeclarationFindings();

        return SellerDeclaration::query()->with(['site', 'publisher.sites'])
            ->where('publisher_id', $publisher->id)->orderBy('seller_id')->orderBy('site_id')->get()
            ->map(function (SellerDeclaration $declaration) use ($network): array {
                $summary = $this->declarationOverview($declaration, $network);
                $payload = collect($network['sellers'])->first(
                    fn (array $seller): bool => (string) data_get($seller, 'payload.seller_id') === (string) $declaration->seller_id,
                );

                return [
                    'seller_id' => (string) $declaration->seller_id,
                    'seller_type' => (string) $declaration->seller_type,
                    'public_name' => $declaration->is_confidential ? null : (string) data_get($payload, 'payload.name', $declaration->name),
                    'public_domain' => $declaration->is_confidential ? null : (string) data_get($payload, 'payload.domain', $declaration->domain),
                    'is_confidential' => (bool) $declaration->is_confidential,
                    'status' => $summary['status'],
                    'review_status' => $summary['review_status'],
                    'sellers_json_health' => $summary['published'] ? 'HEALTHY' : ($summary['status'] === SellerDeclarationStatus::Disabled ? 'INACTIVE' : 'CONFLICT'),
                    'ads_txt_health' => $summary['ads_txt_health'],
                    'schain_health' => $summary['schain_health'],
                    'sites' => $summary['sites']->map(fn (array $site): array => [
                        'name' => $site['site']->display_name,
                        'domain' => $site['site']->primary_domain,
                        'ads_txt_health' => $site['ads_txt_health'],
                        'schain_health' => $site['schain_health'],
                    ])->all(),
                ];
            })->all();
    }

    /** @param array<string, mixed>|null $network
     * @return array<string, mixed>
     */
    private function networkWithStoredDeclarationFindings(?array $network = null): array
    {
        $network ??= $this->invariants->sellers();
        if (! array_key_exists('declaration_findings', $network)) {
            $network['declaration_findings'] = $this->storedDeclarationFindings()->all();
        }
        $network['findings'] = collect($network['findings'])->reject(
            fn (array $finding): bool => in_array($finding['code'], ['SELLER_ID_CONFLICT', 'DUPLICATE_SELLER_DECLARATION'], true),
        )->merge($network['declaration_findings'])->values()->all();

        return $network;
    }

    /** @return Collection<int, array<string, string>> */
    private function storedDeclarationFindings(): Collection
    {
        return SellerDeclaration::withoutGlobalScope('organization')
            ->orderBy('seller_id')->orderBy('id')->get()
            ->groupBy(fn (SellerDeclaration $declaration): string => (string) $declaration->seller_id)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(function (Collection $group, string $sellerId): array {
                $identities = $group->map(fn (SellerDeclaration $declaration): string => hash('sha256', json_encode([
                    (string) $declaration->publisher_id,
                    strtoupper((string) $declaration->seller_type),
                    (bool) $declaration->is_confidential,
                    trim((string) $declaration->name),
                    strtolower(trim((string) $declaration->domain)),
                ], JSON_THROW_ON_ERROR)))->unique();

                return $this->finding(
                    $identities->count() === 1 ? 'DUPLICATE_SELLER_DECLARATION' : 'SELLER_ID_CONFLICT',
                    $identities->count() === 1 ? 'WARNING' : 'ERROR',
                    $identities->count() === 1
                        ? 'Equivalent seller declarations share this seller ID and collapse to one public entry when active.'
                        : 'This seller ID is stored against conflicting paid entities or identities, including disabled declarations.',
                    sellerId: $sellerId,
                );
            })->values();
    }

    private function aggregateHealth(Collection $summaries, string $key): string
    {
        if ($summaries->isEmpty()) {
            return 'NOT_CONFIGURED';
        }
        $values = $summaries->pluck($key);
        if ($values->contains('CONFLICT')) {
            return 'CONFLICT';
        }
        if ($values->contains('PARTIAL') || $values->contains('NOT_CONFIGURED')) {
            return 'PARTIAL';
        }

        return 'HEALTHY';
    }

    /** @return array<string, string> */
    private function finding(
        string $code,
        string $severity,
        string $message,
        ?SellerDeclaration $declaration = null,
        ?string $sellerId = null,
        ?Site $site = null,
    ): array {
        return array_filter([
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'seller_id' => $sellerId ?? $declaration?->seller_id,
            'site_id' => $site?->id,
            'site_domain' => $site?->primary_domain,
        ], fn ($value) => $value !== null);
    }
}
