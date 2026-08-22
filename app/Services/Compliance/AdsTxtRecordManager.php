<?php

namespace App\Services\Compliance;

use App\Enums\SupplyChainReviewStatus;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\SupplyChain\AdsTxtBulkParser;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdsTxtRecordManager
{
    public function __construct(
        private readonly SupplyChainInvariantService $invariants,
        private readonly AdsTxtBulkParser $bulkParser,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(array $attributes, User $actor): DemandAdsTxtRecord
    {
        return DB::transaction(function () use ($attributes, $actor): DemandAdsTxtRecord {
            [$account, $site, $normalized] = $this->normalize($attributes);
            $this->assertUnique($account, $site, $normalized['record_hash']);
            $record = DemandAdsTxtRecord::withoutGlobalScopes()->create($normalized + [
                'demand_account_id' => $account->id,
                'site_id' => $site?->id,
                'status' => 'ACTIVE',
                'source' => 'MANUAL',
                'review_status' => SupplyChainReviewStatus::Verified,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ]);
            $this->audit->record('supply_chain.ads_txt.record_created', $record->organization_id, $actor, $record, newValues: $this->auditValues($record));

            return $record;
        });
    }

    public function update(DemandAdsTxtRecord $record, array $attributes, User $actor): DemandAdsTxtRecord
    {
        return DB::transaction(function () use ($record, $attributes, $actor): DemandAdsTxtRecord {
            $this->assertManaged($record);
            [$account, $site, $normalized] = $this->normalize($attributes);
            $this->assertUnique($account, $site, $normalized['record_hash'], $record);
            $before = $this->auditValues($record);
            $record->update($normalized + [
                'demand_account_id' => $account->id,
                'site_id' => $site?->id,
                'status' => 'ACTIVE',
                'review_status' => SupplyChainReviewStatus::Verified,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'last_verified_at' => null,
            ]);
            $this->audit->record('supply_chain.ads_txt.record_updated', $record->organization_id, $actor, $record, $before, $this->auditValues($record));

            return $record->refresh();
        });
    }

    public function disable(DemandAdsTxtRecord $record, User $actor): DemandAdsTxtRecord
    {
        return DB::transaction(function () use ($record, $actor): DemandAdsTxtRecord {
            $this->assertManaged($record);
            $before = $this->auditValues($record);
            $record->update(['status' => 'DISABLED']);
            $this->audit->record('supply_chain.ads_txt.record_disabled', $record->organization_id, $actor, $record, $before, $this->auditValues($record));

            return $record->refresh();
        });
    }

    /** @return array{created: int, skipped: int} */
    public function bulkAssign(array $attributes, array $siteIds, User $actor): array
    {
        $siteIds = array_values(array_unique($siteIds));
        if ($siteIds === [] || count($siteIds) > 100) {
            throw ValidationException::withMessages(['site_ids' => 'Select between 1 and 100 websites.']);
        }
        $account = DemandAccount::withoutGlobalScopes()->findOrFail($attributes['demand_account_id']);
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($attributes, $siteIds, $account, $actor, &$created, &$skipped): void {
            foreach ($siteIds as $siteId) {
                $site = Site::withoutGlobalScopes()->findOrFail($siteId);
                $normalized = $this->invariants->normalizeDemandRecord($account, $site, $attributes);
                $exists = DemandAdsTxtRecord::withoutGlobalScopes()
                    ->where('demand_account_id', $account->id)->where('site_id', $site->id)
                    ->where('record_hash', $normalized['record_hash'])->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }
                DemandAdsTxtRecord::withoutGlobalScopes()->create($normalized + [
                    'demand_account_id' => $account->id,
                    'site_id' => $site->id,
                    'status' => 'ACTIVE',
                    'source' => 'MANUAL',
                    'review_status' => SupplyChainReviewStatus::Verified,
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->id,
                ]);
                $created++;
            }
            $this->audit->record('supply_chain.ads_txt.bulk_assigned', $account->organization_id, $actor, $account, newValues: [
                'site_ids' => $siteIds,
                'created' => $created,
                'skipped' => $skipped,
                'record' => collect($attributes)->only(['domain', 'publisher_account_id', 'relationship', 'certification_authority_id'])->all(),
            ]);
        });

        return compact('created', 'skipped');
    }

    /** @return array{created: int, skipped: int, invalid: list<array{line: int, content: string, message: string}>, ignored: int, duplicates: int, total_lines: int} */
    public function bulkImport(string $contents, string $accountId, ?string $siteId, User $actor): array
    {
        $parsed = $this->bulkParser->parse($contents);
        $account = DemandAccount::withoutGlobalScopes()->findOrFail($accountId);
        $site = filled($siteId) ? Site::withoutGlobalScopes()->findOrFail($siteId) : null;
        $created = 0;
        $skipped = 0;
        $invalid = $parsed['invalid'];

        DB::transaction(function () use ($parsed, $account, $site, $actor, &$created, &$skipped, &$invalid): void {
            foreach ($parsed['records'] as $item) {
                try {
                    $normalized = $this->invariants->normalizeDemandRecord($account, $site, [
                        'domain' => $item['domain'],
                        'publisher_account_id' => $item['publisher_account_id'],
                        'relationship' => $item['relationship'],
                        'certification_authority_id' => $item['certification_authority_id'],
                    ]);
                } catch (ValidationException $exception) {
                    $invalid[] = [
                        'line' => $item['source_line'],
                        'content' => $item['raw_record'],
                        'message' => collect($exception->errors())->flatten()->first() ?: 'Record is not valid for the selected demand scope.',
                    ];

                    continue;
                }

                $exists = DemandAdsTxtRecord::withoutGlobalScopes()
                    ->where('demand_account_id', $account->id)
                    ->where('site_id', $site?->id)
                    ->where('record_hash', $normalized['record_hash'])
                    ->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }

                DemandAdsTxtRecord::withoutGlobalScopes()->create($normalized + [
                    'demand_account_id' => $account->id,
                    'site_id' => $site?->id,
                    'status' => 'ACTIVE',
                    'source' => 'MANUAL',
                    'review_status' => SupplyChainReviewStatus::Verified,
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->id,
                ]);
                $created++;
            }

            $this->audit->record('supply_chain.ads_txt.bulk_imported', $account->organization_id, $actor, $account, newValues: [
                'site_id' => $site?->id,
                'created' => $created,
                'skipped' => $skipped,
                'invalid_count' => count($invalid),
                'ignored' => $parsed['ignored'],
                'input_duplicates' => $parsed['duplicates'],
                'total_lines' => $parsed['total_lines'],
            ]);
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'ignored' => $parsed['ignored'],
            'duplicates' => $parsed['duplicates'],
            'total_lines' => $parsed['total_lines'],
        ];
    }

    /** @return array{DemandAccount, ?Site, array<string, mixed>} */
    private function normalize(array $attributes): array
    {
        $account = DemandAccount::withoutGlobalScopes()->findOrFail($attributes['demand_account_id']);
        $site = filled($attributes['site_id'] ?? null) ? Site::withoutGlobalScopes()->findOrFail($attributes['site_id']) : null;

        return [$account, $site, $this->invariants->normalizeDemandRecord($account, $site, $attributes)];
    }

    private function assertUnique(DemandAccount $account, ?Site $site, string $hash, ?DemandAdsTxtRecord $except = null): void
    {
        $exists = DemandAdsTxtRecord::withoutGlobalScopes()
            ->where('demand_account_id', $account->id)
            ->where('site_id', $site?->id)
            ->where('record_hash', $hash)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['publisher_account_id' => 'This managed ads.txt record already exists for the selected scope.']);
        }
    }

    private function assertManaged(DemandAdsTxtRecord $record): void
    {
        if ($record->source !== 'MANUAL') {
            throw ValidationException::withMessages(['record' => 'Connector-managed records are read-only here and must be changed at their source.']);
        }
    }

    /** @return array<string, mixed> */
    private function auditValues(DemandAdsTxtRecord $record): array
    {
        return $record->only([
            'demand_account_id', 'site_id', 'domain', 'publisher_account_id', 'relationship',
            'certification_authority_id', 'status', 'source', 'review_status',
        ]);
    }
}
