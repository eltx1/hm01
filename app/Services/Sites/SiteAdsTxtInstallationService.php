<?php

namespace App\Services\Sites;

use App\Enums\SupplyChainReviewStatus;
use App\Enums\VerificationMethod;
use App\Models\PlatformAdsTxtRecord;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteVerification;
use App\Models\User;
use App\Services\Compliance\AdsTxtFetcher;
use App\Services\Compliance\AdsTxtParser;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Validation\ValidationException;

final class SiteAdsTxtInstallationService
{
    public function __construct(
        private readonly HorusSellerIdentityService $identities,
        private readonly SupplyChainStandardsContract $supplyChain,
        private readonly AdsTxtFetcher $fetcher,
        private readonly AdsTxtParser $parser,
    ) {}

    /** @return array{available: bool, core_records: list<string>, records: list<string>, content: string, ads_txt_url: string} */
    public function bundle(Site $site): array
    {
        $site->loadMissing('publisher');
        $hmp = $this->identities->managedForPublisher($site->publisher);
        $hms = $this->identities->managedForSite($site);
        $system = $this->supplyChain->horusAdvertisingSystemDomain();
        $core = $hmp && $hms ? [
            $system.', '.$hmp->seller_id.', DIRECT',
            $system.', '.$hms->seller_id.', DIRECT',
        ] : [];

        $master = PlatformAdsTxtRecord::query()
            ->where('status', 'ACTIVE')
            ->where('review_status', SupplyChainReviewStatus::Verified->value)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderBy('advertising_system_domain')->orderBy('publisher_account_id')->orderBy('id')
            ->get()->map(fn (PlatformAdsTxtRecord $record): string => trim((string) ($record->raw_record ?: implode(', ', array_filter([
                $record->advertising_system_domain,
                $record->publisher_account_id,
                $record->relationship,
                $record->certification_authority_id,
            ])))))->all();
        $applicable = $this->supplyChain->adsTxtForSite($site)['lines'];
        $records = collect(array_merge($core, $master, $applicable))
            ->map(fn (string $line): string => trim($line))
            ->filter()->unique()->values()->all();

        return [
            'available' => count($core) === 2,
            'core_records' => $core,
            'records' => $records,
            'content' => implode("\n", $records).($records === [] ? '' : "\n"),
            'ads_txt_url' => 'https://'.$site->primary_domain.'/ads.txt',
        ];
    }

    public function verify(Site $site, SiteDomain $domain, ?User $actor = null): SiteVerification
    {
        $bundle = $this->bundle($site);
        if (! $bundle['available']) {
            throw ValidationException::withMessages([
                'website_verification' => 'Horus Publisher and Website seller IDs must be reserved before ads.txt verification.',
            ]);
        }

        $verification = SiteVerification::create([
            'organization_id' => $domain->organization_id,
            'site_id' => $site->id,
            'site_domain_id' => $domain->id,
            'method' => VerificationMethod::AdsTxt,
            'expected_value' => implode("\n", $bundle['core_records']),
            'attempted_at' => now(),
        ]);
        $fetch = $this->fetcher->fetchDomain($domain->domain);
        $verified = false;
        $failure = null;
        $evidence = ['checked_domain' => $domain->domain, 'required_core_records' => 2];

        if (! ($fetch['ok'] ?? false)) {
            $failure = 'The live ads.txt file could not be fetched safely ('.($fetch['error_code'] ?? 'FETCH_FAILED').').';
            $evidence['failure_code'] = $fetch['error_code'] ?? 'FETCH_FAILED';
        } else {
            $body = (string) $fetch['body'];
            $parsed = collect($this->parser->parse($body)['records']);
            $required = collect($bundle['core_records'])->map(function (string $line): array {
                [$system, $sellerId] = array_map('trim', explode(',', $line, 3));

                return ['system' => strtolower($system), 'seller_id' => $sellerId];
            });
            $missing = $required->reject(fn (array $expected): bool => $parsed->contains(fn (array $record): bool =>
                strtolower((string) $record['domain']) === $expected['system']
                && (string) $record['publisher_account_id'] === $expected['seller_id']
                && strtoupper((string) $record['relationship']) === 'DIRECT'
            ))->values();
            $verified = $missing->isEmpty();
            $failure = $verified ? null : 'Publish both required Horus DIRECT records, then check again.';
            $evidence += [
                'final_url' => $fetch['final_url'],
                'http_status' => $fetch['http_status'],
                'evidence_sha256' => hash('sha256', $body),
                'missing_core_count' => $missing->count(),
            ];
        }

        $verification->update([
            'status' => $verified ? 'VERIFIED' : 'FAILED',
            'verified_at' => $verified ? now() : null,
            'evidence' => $evidence,
            'failure_reason' => $failure,
        ]);
        if ($verified) {
            $domain->update([
                'verification_status' => 'VERIFIED',
                'verification_method' => VerificationMethod::AdsTxt,
                'verified_at' => now(),
            ]);
        }

        return $verification->fresh();
    }
}
