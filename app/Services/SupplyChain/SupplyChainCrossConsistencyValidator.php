<?php

namespace App\Services\SupplyChain;

use App\Enums\SupplyChainReviewStatus;
use App\Models\SellerDeclaration;
use App\Models\Site;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class SupplyChainCrossConsistencyValidator
{
    public function __construct(
        private readonly SupplyChainStandardsContract $contract,
        private readonly DomainNormalizer $domains,
    ) {}

    /**
     * @param array<string, mixed>|null $network
     * @param array<string, mixed>|null $adsTxt
     * @param array<string, mixed>|null $schain
     * @return array{compliant: bool, seller_id: ?string, owner_domain: ?string, findings: array<int, array<string, mixed>>}
     */
    public function validateSite(Site $site, ?array $network = null, ?array $adsTxt = null, ?array $schain = null): array
    {
        $site->loadMissing('publisher');
        $network ??= $this->contract->sellers();
        $selection = $this->contract->sellerForSite($site, $network);
        $adsTxt ??= $this->contract->adsTxtForSite($site, $network);
        $schain ??= $this->contract->schainForSite($site, $network);
        $findings = collect();

        $systemDomain = $this->contract->horusAdvertisingSystemDomain();
        $selected = $selection['seller'] ?? null;
        $selectedId = $selected ? trim((string) data_get($selected, 'payload.seller_id')) : null;
        $ownerDomain = $this->safeDomain($this->contract->ownerDomainForSite($site));
        $publisherDomain = $this->safeDomain($site->publisher?->business_domain);

        $sellerRows = collect($network['sellers'] ?? [])->values();
        $sellersById = $sellerRows->groupBy(fn (array $seller): string => trim((string) data_get($seller, 'payload.seller_id', $seller['seller_id'] ?? '')));
        $horusAds = $this->horusAdsRecords($adsTxt['lines'] ?? [], $systemDomain);
        $horusNodes = collect($schain['nodes'] ?? [])->filter(
            fn (array $node): bool => strtolower(trim((string) ($node['asi'] ?? ''))) === strtolower($systemDomain),
        )->values();

        foreach (collect($adsTxt['findings'] ?? [])->where('code', 'ADS_TXT_RELATIONSHIP_CONFLICT') as $finding) {
            $findings->push($this->normalizeFinding($finding, $site));
        }
        foreach ($horusAds->groupBy(fn (array $record): string => $record['seller_id']) as $sellerId => $records) {
            if ($records->pluck('relationship')->unique()->count() > 1) {
                $findings->push($this->finding(
                    'ADS_TXT_RELATIONSHIP_CONFLICT',
                    'The same Horus ads.txt seller ID is published with conflicting DIRECT/RESELLER relationships.',
                    $site,
                    (string) $sellerId,
                ));
            }
        }

        $referencedIds = $horusAds->pluck('seller_id')
            ->merge($horusNodes->pluck('sid')->map(fn ($id): string => trim((string) $id)))
            ->when($selectedId !== null && $selectedId !== '', fn (Collection $ids) => $ids->push($selectedId))
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()->values();

        foreach ($referencedIds as $sellerId) {
            $matches = $sellersById->get($sellerId, collect());
            if ($matches->count() === 0) {
                $findings->push($this->finding(
                    'HORUS_SELLER_MISSING_FROM_SELLERS_JSON',
                    'A Horus seller ID referenced by ads.txt or schain has no matching sellers.json Seller object.',
                    $site,
                    $sellerId,
                ));
                continue;
            }
            if ($matches->count() !== 1) {
                $findings->push($this->finding(
                    'HORUS_SELLER_DUPLICATED',
                    'A Horus seller ID referenced by site artifacts resolves to more than one sellers.json Seller object.',
                    $site,
                    $sellerId,
                ));
                continue;
            }

            $this->validatePublisherSellerIdentity($site, $matches->first(), $sellerId, $publisherDomain, $ownerDomain, $findings);
        }

        if ($selectedId !== null && $selectedId !== '') {
            /** @var SellerDeclaration|null $declaration */
            $declaration = is_array($selected) ? ($selected['declaration'] ?? null) : null;
            $expectedRelationship = strtoupper(trim((string) $declaration?->ads_txt_relationship));
            $matchingAds = $horusAds->where('seller_id', $selectedId);
            // Task 39 intentionally authorizes both the Publisher HMP and Website HMS
            // account IDs in ads.txt. Cross-consistency therefore requires the selected
            // transaction ID to be present with its relationship, while any additional
            // Horus IDs are validated independently above as belonging to the same Publisher.
            $adsMatches = in_array($expectedRelationship, ['DIRECT', 'RESELLER'], true)
                && $matchingAds->contains(fn (array $record): bool => $record['relationship'] === $expectedRelationship);
            if (! $adsMatches) {
                $findings->push($this->finding(
                    'HORUS_ADS_TXT_SELLER_MISMATCH',
                    'Canonical ads.txt does not authorize the selected Horus transaction seller ID with its explicitly reviewed relationship.',
                    $site,
                    $selectedId,
                ));
            }

            $matchingNodes = $horusNodes->filter(fn (array $node): bool => trim((string) ($node['sid'] ?? '')) === $selectedId);
            if ($matchingNodes->count() !== 1 || $horusNodes->count() !== 1) {
                $findings->push($this->finding(
                    'SCHAIN_SELLER_MISMATCH',
                    'The Horus SupplyChain node does not resolve exactly once to the selected canonical seller ID.',
                    $site,
                    $selectedId,
                ));
            }

            if ((int) ($schain['complete'] ?? 0) !== 1) {
                $findings->push($this->finding(
                    'SCHAIN_INCOMPLETE',
                    'The represented SupplyChain is partial and must not be treated as complete until it reaches the originating inventory owner.',
                    $site,
                    $selectedId,
                ));
            }
        } elseif ($horusNodes->isNotEmpty()) {
            $findings->push($this->finding(
                'SCHAIN_SELLER_MISMATCH',
                'A Horus SupplyChain node is present without one unambiguous canonical seller selected for the website.',
                $site,
            ));
        }

        try {
            $this->contract->managerDirectiveForSite($site);
        } catch (InvalidArgumentException $exception) {
            $findings->push($this->finding('MANAGERDOMAIN_NOT_AUTHORIZED', $exception->getMessage(), $site, $selectedId));
        }

        $findings = $findings->unique(
            fn (array $finding): string => implode('|', [
                (string) ($finding['code'] ?? ''), (string) ($finding['seller_id'] ?? ''),
                (string) ($finding['site_id'] ?? ''), (string) ($finding['message'] ?? ''),
            ]),
        )->values();

        return [
            'compliant' => ! $findings->contains(fn (array $finding): bool => strtoupper((string) ($finding['severity'] ?? '')) === 'ERROR'),
            'seller_id' => $selectedId,
            'owner_domain' => $ownerDomain,
            'findings' => $findings->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $findings */
    private function validatePublisherSellerIdentity(
        Site $site,
        array $seller,
        string $sellerId,
        ?string $publisherDomain,
        ?string $ownerDomain,
        Collection $findings,
    ): void {
        $payload = is_array($seller['payload'] ?? null) ? $seller['payload'] : $seller;
        if (strtoupper(trim((string) ($payload['seller_type'] ?? ''))) !== 'PUBLISHER') {
            return;
        }

        $publicSellerDomain = $this->safeDomain($payload['domain'] ?? null);
        $review = $site->publisher?->supply_chain_review_status;
        $reviewValue = $review instanceof SupplyChainReviewStatus ? $review->value : strtoupper(trim((string) $review));
        $domainMatches = $publisherDomain !== null
            && $publicSellerDomain !== null
            && $ownerDomain !== null
            && $publisherDomain === $publicSellerDomain
            && $publisherDomain === $ownerDomain;
        if ($reviewValue !== SupplyChainReviewStatus::Verified->value || ! $domainMatches) {
            $findings->push($this->finding(
                'OWNERDOMAIN_SELLER_DOMAIN_MISMATCH',
                'A PUBLISHER seller must expose the reviewed Publisher business domain and owned-site OWNERDOMAIN must resolve to the same identity.',
                $site,
                $sellerId,
            ));
        }

        /** @var SellerDeclaration|null $declaration */
        $declaration = $seller['declaration'] ?? null;
        if ($declaration) {
            $sellerReview = $declaration->review_status;
            $sellerReviewValue = $sellerReview instanceof SupplyChainReviewStatus
                ? $sellerReview->value : strtoupper(trim((string) $sellerReview));
            if ($sellerReviewValue !== SupplyChainReviewStatus::Verified->value) {
                $findings->push($this->finding(
                    'HORUS_SELLER_REVIEW_REQUIRED',
                    'A public Horus seller identity must be reviewed before Admin readiness can claim cross-consistency.',
                    $site,
                    $sellerId,
                ));
            }
        }
    }

    /** @param array<int, string> $lines @return Collection<int, array{seller_id: string, relationship: string}> */
    private function horusAdsRecords(array $lines, string $systemDomain): Collection
    {
        return collect($lines)->map(function (string $line): ?array {
            $fields = array_map('trim', explode(',', $line));
            if (count($fields) < 3) {
                return null;
            }

            return [
                'system' => strtolower($fields[0]),
                'seller_id' => trim($fields[1]),
                'relationship' => strtoupper($fields[2]),
            ];
        })->filter(fn (?array $record): bool => $record !== null && $record['system'] === strtolower($systemDomain))
            ->map(fn (array $record): array => ['seller_id' => $record['seller_id'], 'relationship' => $record['relationship']])->values();
    }

    private function safeDomain(mixed $domain): ?string
    {
        try {
            return $this->domains->normalize(is_string($domain) ? $domain : null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    private function normalizeFinding(array $finding, Site $site): array
    {
        return array_filter([
            'code' => (string) ($finding['code'] ?? 'ADS_TXT_RELATIONSHIP_CONFLICT'),
            'severity' => strtoupper((string) ($finding['severity'] ?? 'ERROR')),
            'message' => (string) ($finding['message'] ?? 'Conflicting canonical ads.txt relationships were detected.'),
            'seller_id' => $finding['seller_id'] ?? null,
            'site_id' => $finding['site_id'] ?? $site->id,
            'site_domain' => $finding['site_domain'] ?? $site->primary_domain,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function finding(string $code, string $message, Site $site, ?string $sellerId = null): array
    {
        return array_filter([
            'code' => $code, 'severity' => 'ERROR', 'message' => $message,
            'seller_id' => $sellerId, 'site_id' => $site->id, 'site_domain' => $site->primary_domain,
        ], fn ($value) => $value !== null);
    }
}
