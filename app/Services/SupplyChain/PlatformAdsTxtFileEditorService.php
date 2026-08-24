<?php

namespace App\Services\SupplyChain;

use App\Enums\SupplyChainReviewStatus;
use App\Models\PlatformAdsTxtRecord;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformAdsTxtFileEditorService
{
    public function __construct(
        private readonly AdsTxtBulkParser $parser,
        private readonly AuditRecorder $audit,
    ) {}

    public function currentFile(): string
    {
        $lines = $this->currentRecords()->pluck('raw_record')->map(fn ($line): string => trim((string) $line))->filter()->values();

        return $lines->implode("\n").($lines->isEmpty() ? '' : "\n");
    }

    /** @return array<string, mixed> */
    public function preview(string $contents): array
    {
        $parsed = $this->parseTarget($contents);
        $invalid = $parsed['invalid'];
        $targetByIdentity = collect();

        foreach ($parsed['records'] as $record) {
            $identity = $this->identity($record['domain'], $record['publisher_account_id']);
            if ($targetByIdentity->has($identity)) {
                $previous = $targetByIdentity->get($identity);
                if ($previous['raw_record'] !== $record['raw_record']) {
                    $invalid[] = [
                        'line' => $record['source_line'],
                        'content' => $record['raw_record'],
                        'message' => 'The same advertising-system seller identity appears more than once with conflicting fields.',
                    ];
                }
                continue;
            }
            $targetByIdentity->put($identity, $record);
        }

        $currentByIdentity = $this->currentRecords()->keyBy(
            fn (PlatformAdsTxtRecord $record): string => $this->identity($record->advertising_system_domain, $record->publisher_account_id),
        );
        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        foreach ($targetByIdentity as $identity => $target) {
            /** @var PlatformAdsTxtRecord|null $existing */
            $existing = $currentByIdentity->get($identity);
            if (! $existing) {
                $added[] = $target['raw_record'];
                continue;
            }
            if (hash_equals((string) $existing->record_hash, hash('sha256', $target['raw_record']))) {
                $unchanged[] = $target['raw_record'];
            } else {
                $changed[] = [
                    'before' => $existing->raw_record,
                    'after' => $target['raw_record'],
                ];
            }
        }

        foreach ($currentByIdentity as $identity => $existing) {
            if (! $targetByIdentity->has($identity)) {
                $removed[] = $existing->raw_record;
            }
        }

        $normalizedLines = $targetByIdentity->values()->pluck('raw_record')->sort()->values();
        $normalized = $normalizedLines->implode("\n").($normalizedLines->isEmpty() ? '' : "\n");

        return [
            'current_count' => $currentByIdentity->count(),
            'target_count' => $targetByIdentity->count(),
            'added_count' => count($added),
            'removed_count' => count($removed),
            'changed_count' => count($changed),
            'unchanged_count' => count($unchanged),
            'invalid_count' => count($invalid),
            'duplicates' => $parsed['duplicates'],
            'ignored' => $parsed['ignored'],
            'total_lines' => $parsed['total_lines'],
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'invalid' => $invalid,
            'normalized_content' => $normalized,
        ];
    }

    /** @return array<string, mixed> */
    public function replace(string $contents, User $actor, string $reason): array
    {
        $preview = $this->preview($contents);
        if ($preview['invalid_count'] > 0) {
            throw ValidationException::withMessages([
                'master_ads_txt' => 'Fix all invalid or conflicting rows before replacing the master ads.txt file.',
            ]);
        }

        $parsed = $this->parseTarget($contents);
        $target = collect($parsed['records'])->keyBy(
            fn (array $record): string => $this->identity($record['domain'], $record['publisher_account_id']),
        );

        DB::transaction(function () use ($target, $actor, $reason, $preview): void {
            $allExisting = PlatformAdsTxtRecord::query()->lockForUpdate()->get()->keyBy(
                fn (PlatformAdsTxtRecord $record): string => $this->identity($record->advertising_system_domain, $record->publisher_account_id),
            );

            foreach ($target as $identity => $item) {
                /** @var PlatformAdsTxtRecord|null $record */
                $record = $allExisting->get($identity);
                if (! $record) {
                    $record = PlatformAdsTxtRecord::create([
                        'advertising_system_domain' => $item['domain'],
                        'publisher_account_id' => $item['publisher_account_id'],
                        'relationship' => $item['relationship'],
                        'certification_authority_id' => $item['certification_authority_id'],
                        'raw_record' => $item['raw_record'],
                        'record_hash' => hash('sha256', $item['raw_record']),
                        'status' => 'ACTIVE',
                        'review_status' => SupplyChainReviewStatus::Verified,
                        'remote_verification_status' => 'UNVERIFIED',
                        'reviewed_at' => now(),
                        'reviewed_by' => $actor->id,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    $this->audit->record(
                        'supply_chain.platform_ads_txt.file_added',
                        $actor->organization_id,
                        $actor,
                        $record,
                        newValues: ['raw_record' => $record->raw_record, 'reason' => $reason],
                    );
                    continue;
                }

                $sameRaw = hash_equals((string) $record->record_hash, hash('sha256', $item['raw_record']));
                $alreadyActiveVerified = $record->status === 'ACTIVE'
                    && $record->review_status === SupplyChainReviewStatus::Verified;
                if ($sameRaw && $alreadyActiveVerified) {
                    continue;
                }

                $before = $record->only(['raw_record', 'status', 'review_status', 'relationship', 'certification_authority_id']);
                $record->update([
                    'relationship' => $item['relationship'],
                    'certification_authority_id' => $item['certification_authority_id'],
                    'raw_record' => $item['raw_record'],
                    'record_hash' => hash('sha256', $item['raw_record']),
                    'status' => 'ACTIVE',
                    'review_status' => SupplyChainReviewStatus::Verified,
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->id,
                    'updated_by' => $actor->id,
                    'remote_verification_status' => $sameRaw ? $record->remote_verification_status : 'UNVERIFIED',
                    'remote_error_code' => $sameRaw ? $record->remote_error_code : null,
                    'last_verified_at' => $sameRaw ? $record->last_verified_at : null,
                ]);
                $after = $record->fresh()->only(['raw_record', 'status', 'review_status', 'relationship', 'certification_authority_id']);
                if ($before !== $after) {
                    $this->audit->record(
                        'supply_chain.platform_ads_txt.file_updated',
                        $actor->organization_id,
                        $actor,
                        $record,
                        oldValues: $before,
                        newValues: $after + ['reason' => $reason],
                    );
                }
            }

            $targetIdentities = $target->keys();
            foreach ($allExisting as $identity => $record) {
                if ($record->status !== 'ACTIVE' || $record->review_status !== SupplyChainReviewStatus::Verified || $targetIdentities->contains($identity)) {
                    continue;
                }
                $before = $record->only(['raw_record', 'status', 'review_status']);
                $record->update(['status' => 'DISABLED', 'updated_by' => $actor->id]);
                $this->audit->record(
                    'supply_chain.platform_ads_txt.file_removed',
                    $actor->organization_id,
                    $actor,
                    $record,
                    oldValues: $before,
                    newValues: ['raw_record' => $record->raw_record, 'status' => 'DISABLED', 'review_status' => $record->review_status->value, 'reason' => $reason],
                );
            }

            $this->audit->record(
                'supply_chain.platform_ads_txt.file_replaced',
                $actor->organization_id,
                $actor,
                newValues: [
                    'reason' => $reason,
                    'current_count' => $preview['current_count'],
                    'target_count' => $preview['target_count'],
                    'added' => $preview['added_count'],
                    'removed' => $preview['removed_count'],
                    'changed' => $preview['changed_count'],
                    'unchanged' => $preview['unchanged_count'],
                    'duplicates' => $preview['duplicates'],
                ],
            );
        });

        return $preview;
    }

    private function currentRecords()
    {
        return PlatformAdsTxtRecord::query()
            ->where('status', 'ACTIVE')
            ->where('review_status', SupplyChainReviewStatus::Verified->value)
            ->orderBy('advertising_system_domain')
            ->orderBy('publisher_account_id')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function parseTarget(string $contents): array
    {
        if (trim($contents) === '') {
            return [
                'records' => [],
                'invalid' => [],
                'ignored' => 0,
                'duplicates' => 0,
                'total_lines' => 0,
            ];
        }

        return $this->parser->parse($contents);
    }

    private function identity(string $domain, string $seller): string
    {
        return strtolower(trim($domain))."\0".trim($seller);
    }
}
