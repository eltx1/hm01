<?php

namespace App\Services\SupplyChain;

use App\Enums\SiteStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\PlatformAdsTxtRecord;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use App\Services\SupplyChain\Data\CanonicalAdsTxtSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class PlatformAdsTxtService
{
    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly RemoteUrlSafetyValidator $urls,
        private readonly AdsTxtBulkParser $bulkParser,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(array $attributes, User $actor): PlatformAdsTxtRecord
    {
        $normalized = $this->normalize($attributes);
        $this->assertUniqueIdentity($normalized['advertising_system_domain'], $normalized['publisher_account_id']);
        $record = PlatformAdsTxtRecord::create($normalized + [
            'status' => 'DISABLED',
            'review_status' => SupplyChainReviewStatus::ReviewRequired,
            'remote_verification_status' => 'UNVERIFIED',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit($record, 'supply_chain.platform_ads_txt.created', $actor, [], $this->auditValues($record));
        return $record;
    }

    public function update(PlatformAdsTxtRecord $record, array $attributes, User $actor): PlatformAdsTxtRecord
    {
        $normalized = $this->normalize($attributes);
        $this->assertUniqueIdentity($normalized['advertising_system_domain'], $normalized['publisher_account_id'], $record);
        $before = $this->auditValues($record);
        $record->update($normalized + [
            'review_status' => SupplyChainReviewStatus::ReviewRequired,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'updated_by' => $actor->id,
            'status' => 'DISABLED',
            'remote_verification_status' => 'UNVERIFIED',
            'remote_error_code' => null,
            'last_verified_at' => null,
        ]);
        $this->audit($record, 'supply_chain.platform_ads_txt.updated', $actor, $before, $this->auditValues($record));
        return $record->refresh();
    }

    public function review(PlatformAdsTxtRecord $record, SupplyChainReviewStatus $status, User $actor): PlatformAdsTxtRecord
    {
        if ($status === SupplyChainReviewStatus::Verified) {
            $this->assertPublicAdvertisingSystem($record->advertising_system_domain);
            $this->normalize($record->toArray());
        }
        $before = $this->auditValues($record);
        $record->update([
            'review_status' => $status,
            'reviewed_at' => $status === SupplyChainReviewStatus::ReviewRequired ? null : now(),
            'reviewed_by' => $status === SupplyChainReviewStatus::ReviewRequired ? null : $actor->id,
            'status' => $status === SupplyChainReviewStatus::Rejected ? 'DISABLED' : $record->status,
            'updated_by' => $actor->id,
        ]);
        $this->audit($record, 'supply_chain.platform_ads_txt.reviewed', $actor, $before, $this->auditValues($record));
        return $record->refresh();
    }

    public function enable(PlatformAdsTxtRecord $record, User $actor): PlatformAdsTxtRecord
    {
        if ($record->review_status !== SupplyChainReviewStatus::Verified) {
            throw ValidationException::withMessages(['status' => 'Platform master ads.txt records must be reviewed and VERIFIED before enablement.']);
        }
        $this->assertPublicAdvertisingSystem($record->advertising_system_domain);
        $this->assertUniqueIdentity($record->advertising_system_domain, $record->publisher_account_id, $record);
        $before = $this->auditValues($record);
        $record->update(['status' => 'ACTIVE', 'updated_by' => $actor->id]);
        $this->audit($record, 'supply_chain.platform_ads_txt.enabled', $actor, $before, $this->auditValues($record));
        return $record->refresh();
    }

    public function disable(PlatformAdsTxtRecord $record, User $actor): PlatformAdsTxtRecord
    {
        $before = $this->auditValues($record);
        $record->update(['status' => 'DISABLED', 'updated_by' => $actor->id]);
        $this->audit($record, 'supply_chain.platform_ads_txt.disabled', $actor, $before, $this->auditValues($record));
        return $record->refresh();
    }

    /** @return array{created: int, updated: int, reactivated: int, skipped: int, invalid: list<array{line: int, content: string, message: string}>, ignored: int, duplicates: int, superseded: int, total_lines: int} */
    public function bulkImport(string $contents, User $actor): array
    {
        $parsed = $this->bulkParser->parse($contents);
        $records = [];
        $superseded = 0;
        foreach ($parsed['records'] as $item) {
            $identity = $this->identity($item['domain'], $item['publisher_account_id']);
            if (isset($records[$identity]) && $records[$identity]['raw_record'] !== $item['raw_record']) {
                $superseded++;
            }
            // A pasted partner file may repeat one seller identity with revised
            // fields. The administrator's last line is the intended final value.
            $records[$identity] = $item;
        }

        $created = 0;
        $updated = 0;
        $reactivated = 0;
        $skipped = 0;
        $invalid = $parsed['invalid'];

        DB::transaction(function () use ($records, $parsed, $actor, $superseded, &$created, &$updated, &$reactivated, &$skipped): void {
            $existingByIdentity = PlatformAdsTxtRecord::query()->lockForUpdate()->get()->keyBy(
                fn (PlatformAdsTxtRecord $record): string => $this->identity($record->advertising_system_domain, $record->publisher_account_id),
            );

            foreach ($records as $identity => $item) {
                $attributes = [
                    'advertising_system_domain' => $item['domain'],
                    'publisher_account_id' => $item['publisher_account_id'],
                    'relationship' => $item['relationship'],
                    'certification_authority_id' => $item['certification_authority_id'],
                ];
                $normalized = $this->normalize($attributes);
                /** @var PlatformAdsTxtRecord|null $existing */
                $existing = $existingByIdentity->get($identity);

                if ($existing) {
                    $sameRecord = hash_equals((string) $existing->record_hash, $normalized['record_hash']);
                    $activeAndVerified = $existing->status === 'ACTIVE'
                        && $existing->review_status === SupplyChainReviewStatus::Verified;
                    if ($sameRecord && $activeAndVerified) {
                        $skipped++;
                        continue;
                    }

                    $before = $this->auditValues($existing);
                    $existing->update([
                        'relationship' => $normalized['relationship'],
                        'certification_authority_id' => $normalized['certification_authority_id'],
                        'raw_record' => $normalized['raw_record'],
                        'record_hash' => $normalized['record_hash'],
                        'status' => 'ACTIVE',
                        'review_status' => SupplyChainReviewStatus::Verified,
                        'reviewed_at' => now(),
                        'reviewed_by' => $actor->id,
                        'updated_by' => $actor->id,
                        'remote_verification_status' => $sameRecord ? $existing->remote_verification_status : 'UNVERIFIED',
                        'remote_error_code' => $sameRecord ? $existing->remote_error_code : null,
                        'last_verified_at' => $sameRecord ? $existing->last_verified_at : null,
                    ]);
                    $event = $sameRecord
                        ? 'supply_chain.platform_ads_txt.bulk_reactivated'
                        : 'supply_chain.platform_ads_txt.bulk_updated';
                    $this->audit($existing, $event, $actor, $before, $this->auditValues($existing));
                    if ($sameRecord) {
                        $reactivated++;
                    } else {
                        $updated++;
                    }

                    continue;
                }

                $record = PlatformAdsTxtRecord::create($normalized + [
                    'status' => 'ACTIVE',
                    'review_status' => SupplyChainReviewStatus::Verified,
                    'remote_verification_status' => 'UNVERIFIED',
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->id,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $this->audit($record, 'supply_chain.platform_ads_txt.bulk_created', $actor, [], $this->auditValues($record));
                $created++;
                $existingByIdentity->put($identity, $record);
            }

            $this->audit->record('supply_chain.platform_ads_txt.bulk_imported', $actor->organization_id, $actor, newValues: [
                'activate' => true,
                'mode' => 'ADMIN_APPEND_AND_PUBLISH',
                'created' => $created,
                'updated' => $updated,
                'reactivated' => $reactivated,
                'skipped' => $skipped,
                'invalid_count' => count($invalid),
                'ignored' => $parsed['ignored'],
                'input_duplicates' => $parsed['duplicates'],
                'superseded' => $superseded,
                'total_lines' => $parsed['total_lines'],
            ]);
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'ignored' => $parsed['ignored'],
            'duplicates' => $parsed['duplicates'],
            'superseded' => $superseded,
            'total_lines' => $parsed['total_lines'],
        ];
    }

    /** @return list<CanonicalAdsTxtSource> */
    public function sourcesForSite(Site $site): array
    {
        if (! $this->siteEligible($site)) {
            return [];
        }
        return PlatformAdsTxtRecord::query()
            ->where('status', 'ACTIVE')
            ->where('review_status', SupplyChainReviewStatus::Verified->value)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderBy('advertising_system_domain')->orderBy('publisher_account_id')->orderBy('id')
            ->get()->map(fn (PlatformAdsTxtRecord $record): CanonicalAdsTxtSource => new CanonicalAdsTxtSource(
                'PLATFORM_MASTER', $record->id, $record->advertising_system_domain, $record->publisher_account_id,
                $record->relationship, $record->certification_authority_id, $record->raw_record,
                '1|'.$record->id, $record, null, ['scope' => 'PLATFORM_GLOBAL'],
            ))->all();
    }

    public function impactedSiteCount(): int
    {
        return Site::withoutGlobalScopes()->whereIn('status', [SiteStatus::Approved->value, SiteStatus::Active->value])->count();
    }

    public function verify(PlatformAdsTxtRecord $record, User $actor): PlatformAdsTxtRecord
    {
        $before = $this->auditValues($record);
        try {
            $domain = $this->domains->normalize($record->advertising_system_domain);
            $url = 'https://'.$domain.'/sellers.json';
            $addresses = $this->urls->publicAddresses($url, 'advertising_system_domain');
            $address = collect($addresses)->first(fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $addresses[0];
            $response = Http::connectTimeout(3)->timeout(8)->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$domain.':443:'.$address]],
            ])->withHeaders(['User-Agent' => 'HorusMedia-PlatformAdsTxt-Validator/1.0', 'Accept' => 'application/json, text/plain;q=0.8'])->get($url);
            if (! $response->successful()) {
                return $this->storeVerification($record, 'UNREACHABLE', 'HTTP_'.$response->status(), $actor, $before);
            }
            $payload = $response->json();
            if (! is_array($payload) || ! isset($payload['sellers']) || ! is_array($payload['sellers'])) {
                return $this->storeVerification($record, 'UNVERIFIED', 'INVALID_JSON', $actor, $before);
            }
            $matches = collect($payload['sellers'])->filter(fn ($seller): bool => is_array($seller)
                && (string) ($seller['seller_id'] ?? '') === $record->publisher_account_id)->values();
            if ($matches->isEmpty()) {
                return $this->storeVerification($record, 'CONFLICT', 'SELLER_ID_ABSENT', $actor, $before);
            }
            if ($matches->map(fn (array $seller): string => hash('sha256', json_encode($seller)))->unique()->count() > 1) {
                return $this->storeVerification($record, 'CONFLICT', 'AMBIGUOUS_SELLER_ID', $actor, $before);
            }
            return $this->storeVerification($record, 'VERIFIED', null, $actor, $before);
        } catch (ConnectionException) {
            return $this->storeVerification($record, 'UNREACHABLE', 'CONNECTION_FAILED', $actor, $before);
        } catch (Throwable) {
            return $this->storeVerification($record, 'UNREACHABLE', 'UNSAFE_OR_INVALID_TARGET', $actor, $before);
        }
    }

    public function siteEligible(Site $site): bool
    {
        $status = $site->status instanceof SiteStatus ? $site->status : SiteStatus::tryFrom((string) $site->status);
        return in_array($status, [SiteStatus::Approved, SiteStatus::Active], true);
    }

    private function normalize(array $attributes): array
    {
        try {
            $domain = $this->domains->normalize((string) ($attributes['advertising_system_domain'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['advertising_system_domain' => 'Advertising system domain is invalid.']);
        }
        if ($domain === 'localhost' || ! str_contains($domain, '.') || str_ends_with($domain, '.local') || str_ends_with($domain, '.internal') || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['advertising_system_domain' => 'Advertising system domain must be a public DNS hostname.']);
        }
        $seller = trim((string) ($attributes['publisher_account_id'] ?? ''));
        $relationship = strtoupper(trim((string) ($attributes['relationship'] ?? '')));
        $authority = strtolower(trim((string) ($attributes['certification_authority_id'] ?? '')));
        if ($seller === '' || strlen($seller) > 255 || preg_match('/[\s,\x00-\x1F\x7F]/u', $seller)) {
            throw ValidationException::withMessages(['publisher_account_id' => 'Publisher account / seller ID is invalid.']);
        }
        if (! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            throw ValidationException::withMessages(['relationship' => 'Relationship must be explicitly DIRECT or RESELLER.']);
        }
        if ($authority !== '' && (strlen($authority) > 128 || preg_match('/^[a-z0-9._-]+$/', $authority) !== 1)) {
            throw ValidationException::withMessages(['certification_authority_id' => 'Certification authority ID is invalid.']);
        }
        $effectiveFrom = $attributes['effective_from'] ?? null;
        $effectiveTo = $attributes['effective_to'] ?? null;
        if ($effectiveFrom && $effectiveTo && strtotime((string) $effectiveTo) <= strtotime((string) $effectiveFrom)) {
            throw ValidationException::withMessages(['effective_to' => 'Effective-to must be later than effective-from.']);
        }
        $line = implode(', ', array_filter([$domain, $seller, $relationship, $authority], fn (string $value): bool => $value !== ''));
        return [
            'advertising_system_domain' => $domain,
            'publisher_account_id' => $seller,
            'relationship' => $relationship,
            'certification_authority_id' => $authority ?: null,
            'raw_record' => $line,
            'record_hash' => hash('sha256', $line),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'internal_notes' => filled($attributes['internal_notes'] ?? null) ? trim((string) $attributes['internal_notes']) : null,
        ];
    }

    private function assertPublicAdvertisingSystem(string $domain): void
    {
        $this->urls->assertPublicHttpUrl('https://'.$domain.'/sellers.json', 'advertising_system_domain');
    }

    private function assertUniqueIdentity(string $domain, string $seller, ?PlatformAdsTxtRecord $except = null): void
    {
        $query = PlatformAdsTxtRecord::query()->where('advertising_system_domain', strtolower($domain))->where('publisher_account_id', $seller);
        if ($except) { $query->whereKeyNot($except->id); }
        if ($query->exists()) {
            throw ValidationException::withMessages(['publisher_account_id' => 'A platform master record already owns this advertising-system seller identity.']);
        }
    }

    private function identity(string $domain, string $seller): string
    {
        return strtolower(trim($domain))."\0".trim($seller);
    }

    private function storeVerification(PlatformAdsTxtRecord $record, string $status, ?string $error, User $actor, array $before): PlatformAdsTxtRecord
    {
        $record->update(['remote_verification_status' => $status, 'remote_error_code' => $error, 'last_verified_at' => now(), 'updated_by' => $actor->id]);
        $this->audit($record, 'supply_chain.platform_ads_txt.verified', $actor, $before, $this->auditValues($record));
        return $record->refresh();
    }

    private function audit(PlatformAdsTxtRecord $record, string $event, User $actor, array $before, array $after): void
    {
        $this->audit->record($event, $actor->organization_id, $actor, $record, $before, $after);
    }

    private function auditValues(PlatformAdsTxtRecord $record): array
    {
        return $record->only(['advertising_system_domain', 'publisher_account_id', 'relationship', 'certification_authority_id', 'status', 'review_status', 'effective_from', 'effective_to', 'last_verified_at', 'remote_verification_status', 'remote_error_code']);
    }
}
