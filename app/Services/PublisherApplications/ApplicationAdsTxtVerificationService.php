<?php

namespace App\Services\PublisherApplications;

use App\Enums\PublisherApplicationStatus;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationDomainClaim;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Compliance\AdsTxtFetcher;
use App\Services\Compliance\AdsTxtParser;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Validation\ValidationException;

final class ApplicationAdsTxtVerificationService
{
    public function __construct(
        private readonly HorusSellerIdentityService $identities,
        private readonly SupplyChainStandardsContract $supplyChain,
        private readonly AdsTxtFetcher $fetcher,
        private readonly AdsTxtParser $parser,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{claim: PublisherApplicationDomainClaim, publisher_seller: mixed, website_seller: mixed, records: array<int, string>, ads_txt_url: string} */
    public function reserve(PublisherApplication $application, ?User $actor = null): array
    {
        if (in_array($application->status, [PublisherApplicationStatus::Rejected, PublisherApplicationStatus::Withdrawn], true)) {
            throw ValidationException::withMessages(['primary_domain' => 'Seller identities cannot be reserved for a terminal Publisher application.']);
        }

        $claim = $this->currentClaim($application);
        $sellers = $this->identities->ensureForApplicationClaim($application, $claim, $actor);
        $domain = $this->supplyChain->horusAdvertisingSystemDomain();
        $records = [
            $domain.', '.$sellers['publisher']->seller_id.', DIRECT',
            $domain.', '.$sellers['website']->seller_id.', DIRECT',
        ];

        return [
            'claim' => $claim->fresh(['publisherSeller', 'websiteSeller']),
            'publisher_seller' => $sellers['publisher'],
            'website_seller' => $sellers['website'],
            'records' => $records,
            'ads_txt_url' => 'https://'.$claim->normalized_domain.'/ads.txt',
        ];
    }

    /** @return array<string, mixed> */
    public function verify(PublisherApplication $application, User $actor): array
    {
        $reserved = $this->reserve($application, $actor);
        /** @var PublisherApplicationDomainClaim $claim */
        $claim = $reserved['claim'];
        $hmp = $reserved['publisher_seller'];
        $hms = $reserved['website_seller'];
        $fetch = $this->fetcher->fetchDomain($claim->normalized_domain);
        $now = now();
        $attempt = (int) $claim->verification_attempt_count + 1;

        if (! ($fetch['ok'] ?? false)) {
            $claim->update([
                'verification_status' => 'FAILED',
                'last_checked_at' => $now,
                'verified_at' => null,
                'final_ads_txt_url' => $fetch['final_url'] ?? null,
                'verification_http_status' => $fetch['http_status'] ?? null,
                'verification_content_type' => $fetch['content_type'] ?? null,
                'evidence_sha256' => null,
                'failure_code' => $fetch['error_code'] ?? 'FETCH_FAILED',
                'verification_attempt_count' => $attempt,
            ]);
            $this->auditAttempt($claim->fresh(), $actor, false, (string) ($fetch['error_code'] ?? 'FETCH_FAILED'), $hmp->seller_id, $hms->seller_id);

            return ['verified' => false, 'code' => $fetch['error_code'] ?? 'FETCH_FAILED', 'claim' => $claim->fresh()];
        }

        $body = (string) $fetch['body'];
        $parsed = $this->parser->parse($body);
        $system = strtolower($this->supplyChain->horusAdvertisingSystemDomain());
        $records = collect($parsed['records']);
        $relationshipMismatch = $records->contains(fn (array $record): bool =>
            strtolower((string) $record['domain']) === $system
            && in_array((string) $record['publisher_account_id'], [$hmp->seller_id, $hms->seller_id], true)
            && strtoupper((string) $record['relationship']) !== 'DIRECT'
        );
        $hasHmp = $records->contains(fn (array $record): bool =>
            strtolower((string) $record['domain']) === $system
            && (string) $record['publisher_account_id'] === $hmp->seller_id
            && strtoupper((string) $record['relationship']) === 'DIRECT'
        );
        $hasHms = $records->contains(fn (array $record): bool =>
            strtolower((string) $record['domain']) === $system
            && (string) $record['publisher_account_id'] === $hms->seller_id
            && strtoupper((string) $record['relationship']) === 'DIRECT'
        );

        $failure = match (true) {
            $relationshipMismatch => 'HORUS_RELATIONSHIP_MISMATCH',
            ! $hasHmp => 'PUBLISHER_HMP_AUTHORIZATION_MISSING',
            ! $hasHms => 'WEBSITE_HMS_AUTHORIZATION_MISSING',
            default => null,
        };
        $verified = $failure === null;
        $claim->update([
            'verification_status' => $verified ? 'VERIFIED' : 'FAILED',
            'last_checked_at' => $now,
            'verified_at' => $verified ? $now : null,
            'final_ads_txt_url' => $fetch['final_url'],
            'verification_http_status' => $fetch['http_status'],
            'verification_content_type' => $fetch['content_type'],
            'evidence_sha256' => hash('sha256', $body),
            'failure_code' => $failure,
            'verification_attempt_count' => $attempt,
        ]);
        $this->auditAttempt($claim->fresh(), $actor, $verified, $failure, $hmp->seller_id, $hms->seller_id);

        return [
            'verified' => $verified,
            'code' => $failure ?: 'VERIFIED',
            'claim' => $claim->fresh(),
            'invalid_record_count' => count($parsed['invalid']),
            'final_url' => $fetch['final_url'],
        ];
    }

    public function currentClaim(PublisherApplication $application): PublisherApplicationDomainClaim
    {
        $domain = strtolower(rtrim((string) $application->primary_domain, '.'));
        $claim = $application->domainClaims()->where('normalized_domain', $domain)->first();
        if (! $claim) {
            throw ValidationException::withMessages(['primary_domain' => 'The application does not have a canonical claim for its current website domain.']);
        }

        return $claim;
    }

    /** Clean Task 40 handoff: only real ads.txt-verified application domains are crawl-eligible. */
    public function crawlingEligible(PublisherApplication $application): bool
    {
        $claim = $application->domainClaim;

        return $claim !== null
            && $claim->normalized_domain === strtolower(rtrim((string) $application->primary_domain, '.'))
            && $claim->verification_status === 'VERIFIED';
    }

    private function auditAttempt(PublisherApplicationDomainClaim $claim, User $actor, bool $verified, ?string $failure, string $hmp, string $hms): void
    {
        $this->audit->record(
            'publisher_application.domain_ads_txt_verification_attempted',
            $claim->application->organization_id,
            $actor,
            $claim,
            newValues: [
                'normalized_domain' => $claim->normalized_domain,
                'verification_status' => $claim->verification_status,
                'publisher_seller_id' => $hmp,
                'website_seller_id' => $hms,
                'verified' => $verified,
                'failure_code' => $failure,
                'evidence_sha256' => $claim->evidence_sha256,
                'final_ads_txt_url' => $claim->final_ads_txt_url,
                'http_status' => $claim->verification_http_status,
            ],
        );
    }
}
