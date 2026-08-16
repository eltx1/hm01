<?php

namespace App\Services\Compliance;

use App\Enums\BidderAdsTxtRequirement;
use App\Models\BidderAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\PlatformAdsTxtRecord;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\SiteServingSetting;
use App\Models\StaticGlobalArtifactChange;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\Privacy\PrivacyReadinessService;
use App\Services\SupplyChain\ManagedAdsTxtDelegationService;
use App\Services\SupplyChain\PlatformAdsTxtService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainPublicOriginVerifier;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Support\Collection;

final class SupplyChainControlCenterService
{
    public const SECTIONS = [
        'overview', 'master-ads-txt', 'horus-sellers', 'bidder-authorizations',
        'direct-demand-authorizations', 'websites', 'sellers-json', 'findings',
    ];

    public function __construct(
        private readonly AdsTxtComplianceService $adsTxt,
        private readonly SupplyChainComplianceService $compliance,
        private readonly SupplyChainStandardsContract $contract,
        private readonly SupplyChainArtifactBuilder $artifacts,
        private readonly ManagedAdsTxtDelegationService $delegation,
        private readonly SupplyChainPublicOriginVerifier $publicOrigin,
        private readonly PlatformAdsTxtService $platformAdsTxt,
        private readonly BidderAdsTxtService $bidderAdsTxt,
        private readonly PrivacyReadinessService $privacy,
    ) {}

    /** @return array<string, mixed> */
    public function section(string $section): array
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return match ($section) {
            'master-ads-txt' => $this->masterRecords(),
            'horus-sellers' => $this->sellers(),
            'bidder-authorizations' => $this->bidderAccounts(),
            'direct-demand-authorizations' => $this->demandRecords(),
            'websites' => $this->websites(),
            'sellers-json' => $this->sellersJson(),
            'findings' => $this->findings(),
            default => $this->overview(),
        };
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $network = $this->compliance->networkOverview();
        $sites = Site::withoutGlobalScopes()->with('publisher')->orderBy('primary_domain')->get();
        $siteRows = $sites->map(fn (Site $site): array => $this->websiteRow($site));
        $findings = collect($network['findings']);

        return [
            'site_count' => $sites->count(),
            'compliant_site_count' => $siteRows->where('status', 'COMPLIANT')->count(),
            'site_rows' => $siteRows,
            'active_seller_count' => count((array) data_get($network, 'payload.sellers', [])),
            'active_master_count' => PlatformAdsTxtRecord::query()->where('status', 'ACTIVE')->count(),
            'active_bidder_record_count' => \App\Models\BidderAdsTxtRecord::withoutGlobalScopes()->where('status', 'ACTIVE')->count(),
            'active_demand_record_count' => DemandAdsTxtRecord::withoutGlobalScopes()->where('status', 'ACTIVE')->count(),
            'error_count' => $findings->where('severity', 'ERROR')->count(),
            'warning_count' => $findings->where('severity', 'WARNING')->count(),
            'sellers_checksum' => hash('sha256', $this->artifacts->sellersJson()),
            'public_origin' => $this->publicOrigin->readiness(),
            'last_publication' => $this->lastPublication(),
        ];
    }

    /** @return array<string, mixed> */
    public function site(Site $site): array
    {
        $site->loadMissing('publisher');
        $summary = $this->adsTxt->summary($site);
        $supply = $this->compliance->siteOverview($site);
        $settings = SiteServingSetting::withoutGlobalScopes()->where('site_id', $site->id)->first();
        $manager = null;
        try {
            $manager = $this->contract->managerDirectiveForSite($site);
        } catch (\Throwable) {
            // The consistency findings expose the invalid configuration without leaking internals.
        }
        $records = collect($summary['canonical']['records'] ?? []);

        return [
            'site' => $site,
            'owner_domain' => $this->contract->ownerDomainForSite($site),
            'manager_domain' => $manager,
            'seller_id' => $supply['seller_id'] ?? null,
            'canonical' => $summary['canonical'],
            'live_content' => $summary['live_content'],
            'missing' => array_values(array_merge(
                (array) data_get($summary, 'comparison.missing', []),
                (array) data_get($summary, 'comparison.missing_directives', []),
            )),
            'extra' => array_values((array) data_get($summary, 'comparison.additional', [])),
            'conflicts' => array_values(array_merge(
                (array) data_get($summary, 'comparison.conflicts', []),
                (array) data_get($summary, 'comparison.invalid', []),
                collect($summary['canonical']['findings'] ?? [])->where('severity', 'ERROR')->values()->all(),
            )),
            'master_records' => $records->where('source', 'PLATFORM_MASTER')->values(),
            'bidder_records' => $records->filter(fn (array $record): bool => str_starts_with((string) ($record['source'] ?? ''), 'PREBID_BIDDER_'))->values(),
            'demand_records' => $records->reject(fn (array $record): bool => ($record['source'] ?? '') === 'PLATFORM_MASTER'
                || ($record['source'] ?? '') === 'CANONICAL_SELLER_IDENTITY'
                || str_starts_with((string) ($record['source'] ?? ''), 'PREBID_BIDDER_'))->values(),
            'sellers_json_consistency' => [
                'status' => $supply['cross_consistency_status'] ?? 'UNKNOWN',
                'details' => $supply['cross_consistency'] ?? [],
            ],
            'schain' => $supply['schain'] ?? [],
            'findings' => $supply['findings'] ?? [],
            'last_verification' => $summary['last_checked'],
            'last_static_publication' => $this->lastPublication(),
            'managed_redirect' => [
                'mode' => $settings?->ads_txt_deployment_mode?->value ?? 'MANUAL_COPY',
                'target' => $this->delegation->managedUrlForSite($site),
                'status' => $settings?->ads_txt_redirect_status ?? 'NOT_VERIFIED',
                'verified_at' => $settings?->ads_txt_redirect_verified_at,
            ],
            'status' => $summary['status'],
            'action' => $summary['action'],
            'provenance' => $this->provenance($site, $summary['canonical']),
        ];
    }

    /** @return array<string, mixed> */
    public function bidder(BidderAccount $account): array
    {
        $account->loadMissing(['bidder', 'siteMappings.site', 'adsTxtRecords.site', 'financialBinding.source', 'financialBinding.connection']);
        $mappings = $account->siteMappings->filter(fn ($mapping): bool => (bool) $mapping->enabled && $mapping->site !== null);
        $mappedSites = $mappings->pluck('site')->unique('id')->values();
        $requiredMissing = 0;
        $supplyFindings = collect();
        $privacyStates = collect();

        foreach ($mappedSites as $site) {
            $readiness = $this->bidderAdsTxt->readinessForSite($site);
            $accountFindings = collect($readiness['findings'] ?? [])->filter(
                fn (array $finding): bool => ($finding['bidder_account_id'] ?? null) === $account->id,
            );
            $requiredMissing += $accountFindings->where('code', 'BIDDER_ADS_TXT_REQUIRED_MISSING')->count();
            $supplyFindings = $supplyFindings->merge($accountFindings);
            $privacyStates->push((string) data_get($this->privacy->admin($site), 'overall.status', 'UNKNOWN'));
        }

        $requirement = $account->ads_txt_requirement ?? BidderAdsTxtRequirement::Unknown;
        $supplyStatus = match (true) {
            ! $account->enabled || ! $account->bidder?->enabled => 'INACTIVE',
            $requirement === BidderAdsTxtRequirement::Required && $requiredMissing > 0 => 'BLOCKED',
            $requirement === BidderAdsTxtRequirement::Unknown => 'REVIEW_REQUIRED',
            $supplyFindings->contains(fn (array $finding): bool => ($finding['severity'] ?? null) === 'ERROR') => 'BLOCKED',
            default => 'READY',
        };
        $binding = $account->financialBinding;
        $financial = match (true) {
            ! $binding => 'NOT_CONFIGURED',
            ! $binding->is_enabled || ! $binding->report_source_id => 'BLOCKED',
            default => 'READY',
        };
        $privacy = match (true) {
            $privacyStates->contains('BLOCKED') => 'BLOCKED',
            $privacyStates->contains(fn (string $state): bool => in_array($state, ['WARNING', 'STALE'], true)) => 'WARNING',
            $privacyStates->isEmpty() => 'NOT_CONFIGURED',
            default => 'READY',
        };
        $remoteStatuses = $account->adsTxtRecords->map(
            fn ($record): string => $this->bidderAdsTxt->effectiveRemoteStatus($record)->value,
        );

        return [
            'account' => $account,
            'ads_txt_requirement' => $requirement->value,
            'records' => $account->adsTxtRecords,
            'mappings' => $mappings,
            'mapped_sites' => $mappedSites,
            'remote_sellers_json_status' => $remoteStatuses->contains('CONFLICT') ? 'CONFLICT'
                : ($remoteStatuses->contains('UNREACHABLE') ? 'UNREACHABLE'
                    : ($remoteStatuses->contains('STALE') ? 'STALE'
                        : ($remoteStatuses->contains('VERIFIED') ? 'VERIFIED' : 'UNVERIFIED'))),
            'financial_source_readiness' => $financial,
            'privacy_readiness' => $privacy,
            'supply_chain_readiness' => $supplyStatus,
            'findings' => $supplyFindings->values(),
        ];
    }

    /** Publisher-safe deployment state. No bidder/account/provider internals are returned. */
    public function publisherSite(Site $site): array
    {
        $summary = $this->adsTxt->summary($site);
        $settings = SiteServingSetting::withoutGlobalScopes()->where('site_id', $site->id)->first();
        $mode = $settings?->ads_txt_deployment_mode?->value ?? 'MANUAL_COPY';
        $redirectVerified = $settings?->ads_txt_redirect_status === 'VERIFIED';

        return [
            'canonical' => $summary['canonical']['content'],
            'status' => $summary['status'],
            'next_action' => $summary['action'],
            'deployment_mode' => $mode,
            'managed_target' => $this->delegation->managedUrlForSite($site),
            'redirect_status' => $settings?->ads_txt_redirect_status ?? 'NOT_VERIFIED',
            'redirect_verified_at' => $settings?->ads_txt_redirect_verified_at,
            'instructions' => $mode === 'MANAGED_REDIRECT_DELEGATION'
                ? ($redirectVerified
                    ? 'Managed delegation is verified. Horus will update the canonical file automatically; no repeated publisher-side edits are required.'
                    : 'Configure one valid /ads.txt redirect to the managed Horus target below, then run verification.')
                : 'Copy or download the canonical file below and publish it at /ads.txt on your website.',
        ];
    }

    /** @return array<string, mixed> */
    private function masterRecords(): array
    {
        return [
            'records' => PlatformAdsTxtRecord::query()->with(['reviewer', 'creator', 'updater'])->orderBy('advertising_system_domain')->get(),
            'impact_count' => $this->platformAdsTxt->impactedSiteCount(),
            'last_publication' => $this->lastPublication(),
        ];
    }

    /** @return array<string, mixed> */
    private function sellers(): array
    {
        $network = $this->compliance->networkOverview();
        $declarations = SellerDeclaration::withoutGlobalScope('organization')->with(['publisher.sites', 'site', 'reviewer'])
            ->orderBy('seller_id')->orderBy('site_id')->get();

        return [
            'declarations' => $declarations,
            'network' => $network,
            'last_publication' => $this->lastPublication(),
        ];
    }

    /** @return array<string, mixed> */
    private function bidderAccounts(): array
    {
        $accounts = BidderAccount::withoutGlobalScopes()->with(['bidder', 'siteMappings.site', 'adsTxtRecords.site', 'financialBinding.source', 'financialBinding.connection'])
            ->orderBy('name')->get();

        return ['accounts' => $accounts->map(fn (BidderAccount $account): array => $this->bidder($account))];
    }

    /** @return array<string, mixed> */
    private function demandRecords(): array
    {
        return [
            'records' => DemandAdsTxtRecord::withoutGlobalScopes()->with(['account.network', 'site'])->orderBy('domain')->orderBy('publisher_account_id')->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function websites(): array
    {
        $sites = Site::withoutGlobalScopes()->with('publisher')->orderBy('primary_domain')->get();
        return ['sites' => $sites->map(fn (Site $site): array => $this->websiteRow($site))];
    }

    /** @return array<string, mixed> */
    private function sellersJson(): array
    {
        $network = $this->compliance->networkOverview();
        $payload = $network['payload'];
        $declarations = SellerDeclaration::withoutGlobalScope('organization')->with(['publisher.sites', 'site'])
            ->where('status', 'ACTIVE')->get()->keyBy('seller_id');
        $findings = collect($network['findings']);
        $entities = collect($payload['sellers'] ?? [])->map(function (array $seller) use ($declarations, $findings): array {
            $declaration = $declarations->get((string) ($seller['seller_id'] ?? ''));
            $sites = $declaration?->site ? collect([$declaration->site]) : ($declaration?->publisher?->sites ?? collect());
            return [
                'seller' => $seller,
                'review_state' => $declaration?->review_status?->value ?? (string) ($declaration?->review_status ?? 'UNKNOWN'),
                'sites' => $sites,
                'findings' => $findings->filter(fn (array $finding): bool => ($finding['seller_id'] ?? null) === ($seller['seller_id'] ?? null))->values(),
            ];
        });

        return [
            'count' => $entities->count(),
            'entities' => $entities,
            'checksum' => hash('sha256', $this->artifacts->sellersJson()),
            'artifact' => $this->artifacts->sellersJson(),
            'public_origin' => $this->publicOrigin->readiness(),
            'last_publication' => $this->lastPublication(),
        ];
    }

    /** @return array<string, mixed> */
    private function findings(): array
    {
        $network = $this->compliance->networkOverview();
        $siteFindings = collect($network['site_summaries'])->flatMap(function (array $summary): array {
            return collect($summary['findings'] ?? [])->map(function (array $finding) use ($summary): array {
                $finding['site_id'] ??= $summary['site']->id;
                $finding['site_domain'] ??= $summary['site']->primary_domain;
                return $finding;
            })->all();
        });

        return [
            'findings' => collect($network['findings'])->merge($siteFindings)
                ->unique(fn (array $finding): string => hash('sha256', json_encode($finding, JSON_THROW_ON_ERROR)))
                ->sortBy(fn (array $finding): int => match ($finding['severity'] ?? 'INFO') { 'ERROR' => 0, 'WARNING' => 1, default => 2 })
                ->values(),
            'public_origin' => $this->publicOrigin->readiness(),
        ];
    }

    /** @return array<string, mixed> */
    private function websiteRow(Site $site): array
    {
        $summary = $this->adsTxt->summary($site);
        $supply = $this->compliance->siteOverview($site);
        $hasConflict = ($supply['cross_consistency_status'] ?? null) === 'CONFLICT' || $summary['invalid_count'] > 0;
        return [
            'site' => $site,
            'status' => $hasConflict ? 'CONFLICT' : ($summary['status'] === 'COMPLIANT' ? 'COMPLIANT' : $summary['status']),
            'seller_id' => $supply['seller_id'] ?? null,
            'ads_txt' => $summary['status'],
            'sellers_json' => $supply['cross_consistency_status'] ?? 'UNKNOWN',
            'schain' => $supply['schain_health'] ?? 'UNKNOWN',
            'last_checked' => $summary['last_checked'],
            'action' => $summary['action'],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function provenance(Site $site, array $canonical): Collection
    {
        $records = collect($canonical['records'] ?? [])->keyBy(fn (array $record): string => trim((string) ($record['canonical'] ?? '')));
        return collect(preg_split('/\R/', trim((string) ($canonical['content'] ?? ''))) ?: [])->filter()->values()->map(function (string $line) use ($site, $records): array {
            if ($records->has(trim($line))) {
                $record = $records->get(trim($line));
                return [
                    'line' => $line,
                    'source' => $record['source'] ?? 'CANONICAL_RECORD',
                    'source_id' => $record['id'] ?? null,
                    'scope' => $record['scope'] ?? null,
                    'why' => $record['account_label'] ?? 'Reviewed canonical supply-chain authorization.',
                    'provenance' => $record['provenance'] ?? [],
                ];
            }
            if (str_starts_with($line, 'OWNERDOMAIN=')) {
                return ['line' => $line, 'source' => 'PUBLISHER_IDENTITY', 'source_id' => $site->publisher_id, 'scope' => 'PUBLISHER', 'why' => 'Emitted from the publisher legal/business domain used as OWNERDOMAIN.'];
            }
            if (str_starts_with($line, 'MANAGERDOMAIN=')) {
                return ['line' => $line, 'source' => 'SITE_MANAGER_ROLE', 'source_id' => $site->id, 'scope' => 'WEBSITE', 'why' => 'Emitted because this website has an approved Horus monetization-manager role.'];
            }
            if (str_starts_with($line, 'CONTACT=')) {
                return ['line' => $line, 'source' => 'REVIEWED_PLATFORM_DIRECTIVE', 'source_id' => null, 'scope' => 'PLATFORM', 'why' => 'Emitted from the reviewed platform ads.txt CONTACT directive.'];
            }
            if (str_starts_with($line, 'INVENTORYPARTNERDOMAIN=') || str_starts_with($line, 'SUBDOMAIN=')) {
                return ['line' => $line, 'source' => 'PLATFORM_DIRECTIVE', 'source_id' => null, 'scope' => 'PLATFORM', 'why' => 'Emitted from the explicit platform ads.txt directive configuration.'];
            }
            return ['line' => $line, 'source' => 'GENERATOR', 'source_id' => null, 'scope' => 'PLATFORM', 'why' => 'Deterministic header emitted by the Horus supply-chain artifact generator.'];
        });
    }

    /** @return array<string, mixed>|null */
    private function lastPublication(): ?array
    {
        $change = StaticGlobalArtifactChange::query()->with('batch')
            ->where('artifact_type', StaticGlobalArtifactChange::SUPPLY_CHAIN)
            ->where('status', 'DEPLOYED')->latest('delivered_at')->first();
        if (! $change) {
            return null;
        }
        return [
            'id' => $change->id,
            'delivered_at' => $change->delivered_at,
            'manifest_hash' => $change->batch?->manifest_hash,
            'batch_id' => $change->batch_id,
            'event_count' => $change->event_count,
        ];
    }
}
