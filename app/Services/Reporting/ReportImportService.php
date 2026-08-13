<?php

namespace App\Services\Reporting;

use App\Enums\ReportConnectionStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Models\Advertiser;
use App\Models\AdvertiserReport;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\HourlyReport;
use App\Models\ReportError;
use App\Models\ReportImportFile;
use App\Models\ReportImportJob;
use App\Models\ReportSourceConnection;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ReportImportService
{
    public function __construct(
        private readonly ReportDimensionResolver $dimensions,
        private readonly RevenueRuleService $rules,
        private readonly RevenueCalculator $calculator,
        private readonly FinancialPeriodService $periods,
        private readonly ReportSourceManager $sources,
        private readonly ReconciliationService $reconciliation,
        private readonly FinancialSettlementEligibilityService $settlementEligibility,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function runConnection(
        ReportSourceConnection $connection,
        CarbonInterface $from,
        CarbonInterface $to,
        ReportGranularity $granularity,
        ReportFinality $finality,
        ?User $actor = null,
        array $options = [],
    ): ReportImportJob {
        $connection->loadMissing('source');
        try {
            $connection->update(['last_attempted_at' => now(), 'last_error' => null]);
            $payload = $this->sources->for($connection)->fetch(
                $connection,
                $from,
                $to,
                $granularity,
                $finality,
                $options,
            );

            return $this->importRows(
                $connection,
                (array) ($payload['rows'] ?? []),
                $granularity,
                $finality,
                $from,
                $to,
                $actor,
                (string) ($payload['external_report_id'] ?? ''),
                null,
                'API',
                (array) ($payload['totals'] ?? []),
            );
        } catch (Throwable $exception) {
            $key = hash('sha256', implode('|', [
                $connection->id, 'API', $granularity->value, $finality->value,
                $from->toIso8601String(), $to->toIso8601String(), 'failed',
            ]));
            $job = ReportImportJob::withoutGlobalScopes()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'organization_id' => $connection->organization_id,
                    'report_source_connection_id' => $connection->id,
                    'import_type' => 'API',
                    'granularity' => $granularity,
                    'finality' => $finality,
                    'settlement_eligible' => false,
                    'settlement_ineligibility_reason' => 'FETCH_FAILED',
                    'status' => ReportImportStatus::Failed,
                    'period_start' => $from,
                    'period_end' => $to,
                    'attempt_count' => 1,
                    'error_message' => $exception->getMessage(),
                    'next_retry_at' => now()->addMinutes((int) config('reporting.retry_delay_minutes', 30)),
                    'created_by' => $actor?->id,
                ],
            );
            $connection->update(['status' => ReportConnectionStatus::Error, 'last_error' => $exception->getMessage()]);
            $this->recordError($connection, $job, 'SOURCE_IMPORT', 'FETCH_FAILED', $exception->getMessage(), true);

            return $job->refresh();
        }
    }

    public function importRows(
        ReportSourceConnection $connection,
        array $rows,
        ReportGranularity $granularity,
        ReportFinality $finality,
        CarbonInterface $from,
        CarbonInterface $to,
        ?User $actor = null,
        ?string $externalReportId = null,
        ?string $checksum = null,
        string $importType = 'SYSTEM',
        array $sourceTotals = [],
        ?string $manualReason = null,
    ): ReportImportJob {
        $connection->loadMissing('source');
        if ($importType === 'MANUAL') {
            if (! $actor || ! $actor->isHorusAdministrator() || ! $actor->hasPermission('finance.adjustments.create')) {
                abort(403);
            }
            if (mb_strlen(trim((string) $manualReason)) < 12) {
                throw ValidationException::withMessages(['manual_reason' => 'A specific Finance reason of at least 12 characters is required for a manual import.']);
            }
        }
        $settlement = $this->settlementEligibility->forImport($connection, $finality, $importType, $from, $to);
        $effectiveFinality = $settlement['eligible'] ? $finality : ReportFinality::Estimated;
        $normalizedInputHash = hash('sha256', json_encode($this->stable($rows), JSON_THROW_ON_ERROR));
        $checksum ??= $normalizedInputHash;
        $idempotencyKey = hash('sha256', implode('|', [
            $connection->id,
            $importType,
            $granularity->value,
            $effectiveFinality->value,
            $from->toIso8601String(),
            $to->toIso8601String(),
            $externalReportId ?: $checksum,
        ]));

        $existing = ReportImportJob::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $job = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'report_source_connection_id' => $connection->id,
            'import_type' => $importType,
            'granularity' => $granularity,
            'finality' => $effectiveFinality,
            'settlement_eligible' => $settlement['eligible'],
            'settlement_ineligibility_reason' => $settlement['reason'],
            'status' => ReportImportStatus::Processing,
            'period_start' => $from,
            'period_end' => $to,
            'external_report_id' => $externalReportId ?: null,
            'idempotency_key' => $idempotencyKey,
            'checksum' => $checksum,
            'attempt_count' => 1,
            'source_totals' => $sourceTotals ?: null,
            'started_at' => now(),
            'created_by' => $actor?->id,
        ]);

        try {
            $result = DB::transaction(function () use (
                $connection, $rows, $granularity, $finality, $effectiveFinality, $job, $actor, $sourceTotals, $importType, $settlement, $manualReason
            ): array {
                $inserted = 0;
                $updated = 0;
                $duplicates = 0;
                $totals = $this->emptyTotals();
                $periodIds = [];

                foreach ($rows as $index => $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }
                    $row = $this->normalizeRow($raw);
                    if (! $row['date']) {
                        throw ValidationException::withMessages([
                            "rows.{$index}.date" => 'Every report row requires a valid date.',
                        ]);
                    }
                    if ($settlement['eligible'] && strtoupper($row['currency']) !== strtoupper($connection->currency)) {
                        throw ValidationException::withMessages([
                            "rows.{$index}.currency" => 'A settlement-eligible row must match the canonical financial connection currency.',
                        ]);
                    }

                    $period = $this->periods->assertOpen($row['date'], $row['currency']);
                    $periodIds[$period->id] = true;
                    $dimension = $this->dimensions->resolve($row, $connection->organization_id);
                    $context = array_merge($row, [
                        'publisher_id' => $dimension->publisher_id,
                        'site_id' => $dimension->site_id,
                        'campaign_id' => $dimension->campaign_id,
                        'demand_network_id' => $dimension->demand_network_id,
                        'report_source_id' => $connection->report_source_id,
                        'report_source_code' => $connection->source->code->value,
                    ]);
                    $rule = $this->rules->resolve($row['date'], $context, $row['currency']);
                    $financial = $this->calculator->calculate(
                        $row['gross_revenue_minor'],
                        $row['demand_partner_deductions_minor'],
                        $row['invalid_traffic_adjustments_minor'],
                        $row['other_adjustments_minor'],
                        $rule,
                    );
                    $metrics = array_merge($row, $financial);
                    $metrics = array_merge($metrics, $this->calculator->rates($metrics));
                    $organizationId = $dimension->organization_id ?? $connection->organization_id;
                    $sourceRowHash = hash('sha256', json_encode([
                        'connection' => $connection->id,
                        'date' => $row['date'],
                        'hour' => $row['hour'],
                        'dimension' => $dimension->dimension_hash,
                        'finality' => $effectiveFinality->value,
                        'settlement_eligible' => $settlement['eligible'],
                        'metrics' => $this->metricPayload($metrics),
                    ], JSON_THROW_ON_ERROR));

                    if ($granularity === ReportGranularity::Hourly) {
                        [$wasInserted, $wasChanged] = $this->upsertHourly(
                            $connection, $job, $period, $dimension->id, $organizationId,
                            $metrics, $effectiveFinality, $settlement['eligible'], $rule->id, $sourceRowHash,
                        );
                    } else {
                        [$wasInserted, $wasChanged] = $this->upsertDaily(
                            $connection, $job, $period, $dimension->id, $organizationId,
                            $metrics, $effectiveFinality, $settlement['eligible'], $rule->id, $sourceRowHash,
                        );
                    }

                    if ($wasInserted) {
                        $inserted++;
                    } elseif ($wasChanged) {
                        $updated++;
                    } else {
                        $duplicates++;
                    }
                    $this->addTotals($totals, $metrics);

                    if ($dimension->advertiser_id && $dimension->campaign_id) {
                        $this->upsertAdvertiserReport(
                            $connection, $job, $dimension->id, $organizationId,
                            $dimension->advertiser_id, $dimension->campaign_id,
                            $metrics, $effectiveFinality, $sourceRowHash,
                        );
                    }
                }

                $warnings = $this->discrepancyWarnings($sourceTotals, $totals);
                $job->update([
                    'financial_period_id' => count($periodIds) === 1 ? array_key_first($periodIds) : null,
                    'status' => ReportImportStatus::Completed,
                    'row_count' => count($rows),
                    'inserted_count' => $inserted,
                    'updated_count' => $updated,
                    'duplicate_count' => $duplicates,
                    'normalized_totals' => $totals,
                    'warnings' => array_values(array_merge($warnings, $settlement['eligible'] ? [] : [[
                        'code' => 'SETTLEMENT_INELIGIBLE_SOURCE',
                        'reason' => $settlement['reason'],
                    ]])),
                    'completed_at' => now(),
                ]);

                $connection->update([
                    'status' => ReportConnectionStatus::Active,
                    'last_successful_import_at' => now(),
                    'last_finalized_import_at' => $settlement['eligible'] ? now() : $connection->last_finalized_import_at,
                    'last_error' => null,
                ]);

                if ($actor) {
                    $this->audit->record(
                        $importType === 'MANUAL' ? 'reporting.manual_import.completed' : 'reporting.import.completed',
                        $connection->organization_id,
                        $actor,
                        $job,
                        newValues: [
                            'source' => $connection->source->code->value,
                            'granularity' => $granularity->value,
                            'requested_finality' => $finality->value,
                            'effective_finality' => $effectiveFinality->value,
                            'settlement_eligible' => $settlement['eligible'],
                            'manual_reason' => $importType === 'MANUAL' ? trim((string) $manualReason) : null,
                            'rows' => count($rows),
                            'inserted' => $inserted,
                            'updated' => $updated,
                            'duplicates' => $duplicates,
                            'checksum' => $job->checksum,
                        ],
                    );
                }

                return ['totals' => $totals, 'warnings' => $warnings];
            });

            $this->reconciliation->forImport($job->refresh(), $result['totals'], $sourceTotals, $actor);

            return $job->refresh();
        } catch (ValidationException $exception) {
            $closed = collect($exception->errors())->keys()->contains('period');
            $job->update([
                'status' => $closed ? ReportImportStatus::BlockedClosedPeriod : ReportImportStatus::Failed,
                'settlement_eligible' => false,
                'settlement_ineligibility_reason' => $closed ? 'CLOSED_PERIOD' : 'IMPORT_VALIDATION_FAILED',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
            $this->recordError($connection, $job, 'VALIDATION', $closed ? 'CLOSED_PERIOD' : 'INVALID_REPORT', $exception->getMessage(), false);
            return $job->refresh();
        } catch (Throwable $exception) {
            $job->update([
                'status' => ReportImportStatus::Failed,
                'settlement_eligible' => false,
                'settlement_ineligibility_reason' => 'IMPORT_FAILED',
                'error_message' => $exception->getMessage(),
                'next_retry_at' => now()->addMinutes((int) config('reporting.retry_delay_minutes', 30)),
                'completed_at' => now(),
            ]);
            $connection->update(['status' => ReportConnectionStatus::Error, 'last_error' => $exception->getMessage()]);
            $this->recordError($connection, $job, 'IMPORT', 'PROCESSING_FAILED', $exception->getMessage(), true);
            return $job->refresh();
        }
    }

    public function importCsv(
        ReportSourceConnection $connection,
        UploadedFile $file,
        ReportGranularity $granularity,
        ReportFinality $finality,
        CarbonInterface $from,
        CarbonInterface $to,
        User $actor,
    ): ReportImportJob {
        if (! $file->isValid() || $file->getSize() > (int) config('reporting.csv_max_bytes', 25 * 1024 * 1024)) {
            throw ValidationException::withMessages(['report' => 'The CSV report is invalid or exceeds the configured maximum size.']);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['report' => 'Only CSV report files are accepted.']);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $rows = $this->readCsv($file->getRealPath());
        $job = $this->importRows(
            $connection, $rows, $granularity, $finality, $from, $to, $actor,
            externalReportId: null, checksum: $checksum, importType: 'CSV',
        );

        if (! $job->files()->where('checksum', $checksum)->exists()) {
            $path = $file->storeAs(
                'report-imports/'.$connection->id.'/'.$job->id,
                $checksum.'.csv',
                'local',
            );
            ReportImportFile::query()->create([
                'report_import_job_id' => $job->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'checksum' => $checksum,
            ]);
        }

        return $job->refresh();
    }

    public function retry(ReportImportJob $job, ?User $actor = null): ReportImportJob
    {
        if ($job->status !== ReportImportStatus::Failed || ! $job->connection->is_enabled) {
            return $job;
        }

        $job->update([
            'attempt_count' => $job->attempt_count + 1,
            'next_retry_at' => null,
        ]);

        return $this->runConnection(
            $job->connection,
            $job->period_start,
            $job->period_end,
            $job->granularity,
            $job->finality,
            $actor,
        );
    }

    private function upsertHourly(
        ReportSourceConnection $connection,
        ReportImportJob $job,
        FinancialPeriod $period,
        string $dimensionId,
        ?string $organizationId,
        array $metrics,
        ReportFinality $finality,
        bool $settlementEligible,
        ?string $ruleVersionId,
        string $sourceRowHash,
    ): array {
        $identity = [
            'report_source_connection_id' => $connection->id,
            'report_date' => $metrics['date'],
            'report_hour' => (int) ($metrics['hour'] ?? 0),
            'report_dimension_id' => $dimensionId,
        ];
        $existing = HourlyReport::withoutGlobalScopes()->where($identity)->first();
        if ($existing && hash_equals($existing->source_row_hash, $sourceRowHash)) {
            return [false, false];
        }

        HourlyReport::withoutGlobalScopes()->updateOrCreate($identity, array_merge(
            $this->metricPayload($metrics),
            [
                'organization_id' => $organizationId,
                'report_import_job_id' => $job->id,
                'financial_period_id' => $period->id,
                'finality' => $finality,
                'settlement_eligible' => $settlementEligible,
                'currency' => $metrics['currency'],
                'revenue_rule_version_id' => $ruleVersionId,
                'source_row_hash' => $sourceRowHash,
                'revision' => $existing ? $existing->revision + 1 : 1,
            ],
        ));

        return [$existing === null, true];
    }

    private function upsertDaily(
        ReportSourceConnection $connection,
        ReportImportJob $job,
        FinancialPeriod $period,
        string $dimensionId,
        ?string $organizationId,
        array $metrics,
        ReportFinality $finality,
        bool $settlementEligible,
        ?string $ruleVersionId,
        string $sourceRowHash,
    ): array {
        $identity = [
            'report_source_connection_id' => $connection->id,
            'report_date' => $metrics['date'],
            'report_dimension_id' => $dimensionId,
        ];
        $existing = DailyReport::withoutGlobalScopes()->where($identity)->first();
        if ($existing && hash_equals($existing->source_row_hash, $sourceRowHash)) {
            return [false, false];
        }

        DailyReport::withoutGlobalScopes()->updateOrCreate($identity, array_merge(
            $this->metricPayload($metrics),
            [
                'organization_id' => $organizationId,
                'report_import_job_id' => $job->id,
                'financial_period_id' => $period->id,
                'finality' => $finality,
                'settlement_eligible' => $settlementEligible,
                'currency' => $metrics['currency'],
                'revenue_rule_version_id' => $ruleVersionId,
                'source_row_hash' => $sourceRowHash,
                'revision' => $existing ? $existing->revision + 1 : 1,
            ],
        ));

        return [$existing === null, true];
    }

    private function upsertAdvertiserReport(
        ReportSourceConnection $connection,
        ReportImportJob $job,
        string $dimensionId,
        ?string $organizationId,
        string $advertiserId,
        string $campaignId,
        array $metrics,
        ReportFinality $finality,
        string $sourceRowHash,
    ): void {
        $advertiserOrganizationId = Advertiser::withoutGlobalScopes()->find($advertiserId)?->organization_id ?? $organizationId;
        AdvertiserReport::withoutGlobalScopes()->updateOrCreate(
            [
                'campaign_id' => $campaignId,
                'report_source_connection_id' => $connection->id,
                'report_date' => $metrics['date'],
                'report_dimension_id' => $dimensionId,
            ],
            [
                'organization_id' => $advertiserOrganizationId,
                'advertiser_id' => $advertiserId,
                'report_import_job_id' => $job->id,
                'finality' => $finality,
                'currency' => $metrics['currency'],
                'impressions' => $metrics['impressions'],
                'clicks' => $metrics['clicks'],
                'ctr_bp' => $metrics['ctr_bp'],
                'video_starts' => $metrics['video_starts'],
                'completed_views' => $metrics['completed_views'],
                'spend_minor' => $metrics['spend_minor'] ?: $metrics['gross_revenue_minor'],
                'remaining_budget_minor' => $metrics['remaining_budget_minor'],
                'source_row_hash' => $sourceRowHash,
            ],
        );
    }

    private function normalizeRow(array $row): array
    {
        $lower = [];
        foreach ($row as $key => $value) {
            $lower[strtolower(trim((string) $key))] = $value;
        }

        $dateValue = $lower['date'] ?? $lower['report_date'] ?? $lower['day'] ?? null;
        $date = null;
        if ($dateValue !== null && strtotime((string) $dateValue) !== false) {
            $date = CarbonImmutable::parse($dateValue)->toDateString();
        }
        $hour = $lower['hour'] ?? $lower['report_hour'] ?? null;
        if ($hour === null && isset($lower['date_hour'])) {
            $parsed = CarbonImmutable::parse($lower['date_hour']);
            $date = $parsed->toDateString();
            $hour = $parsed->hour;
        }

        $requests = $this->integer($lower['ad_requests'] ?? $lower['requests'] ?? $lower['ad_server_ad_requests'] ?? 0);
        $matched = $this->integer($lower['matched_requests'] ?? $lower['ad_server_matched_requests'] ?? 0);
        $unfilled = $this->integer($lower['unfilled_requests'] ?? $lower['unfilled'] ?? $lower['ad_server_unfilled_impressions'] ?? max(0, $requests - $matched));
        $impressions = $this->integer($lower['impressions'] ?? $lower['ad_server_impressions'] ?? $lower['views'] ?? 0);
        $clicks = $this->integer($lower['clicks'] ?? $lower['ad_server_clicks'] ?? 0);
        $gross = array_key_exists('gross_revenue_minor', $lower)
            ? $this->signedInteger($lower['gross_revenue_minor'])
            : (array_key_exists('revenue_minor', $lower)
                ? $this->signedInteger($lower['revenue_minor'])
                : $this->minorUnits($lower['revenue'] ?? $lower['earnings'] ?? $lower['ad_server_cpm_and_cpc_revenue'] ?? 0));

        $viewability = $lower['viewability_bp'] ?? $lower['viewability'] ?? $lower['active_view_viewable_impressions_rate'] ?? null;
        $viewabilityBp = $viewability === null || $viewability === ''
            ? null
            : (str_contains((string) $viewability, '%')
                ? (int) round((float) str_replace('%', '', (string) $viewability) * 100)
                : ((float) $viewability <= 1 ? (int) round((float) $viewability * 10000) : (int) round((float) $viewability)));

        $aliases = [
            'publisher_id' => ['publisher_id'],
            'site_id' => ['site_id', 'website_id'],
            'placement_id' => ['placement_id'],
            'gam_connection_id' => ['gam_connection_id'],
            'demand_network_id' => ['demand_network_id'],
            'bidder_id' => ['bidder_id', 'bidder'],
            'advertiser_id' => ['advertiser_id'],
            'campaign_id' => ['campaign_id'],
            'country_code' => ['country_code', 'country'],
            'device' => ['device', 'device_category_name'],
            'browser' => ['browser', 'browser_name'],
            'operating_system' => ['operating_system', 'operating_system_name', 'os'],
            'ad_size' => ['ad_size', 'creative_size', 'size'],
        ];
        $dimensions = [];
        foreach ($aliases as $target => $keys) {
            foreach ($keys as $key) {
                if (isset($lower[$key]) && (string) $lower[$key] !== '') {
                    $dimensions[$target] = $lower[$key];
                    break;
                }
            }
        }

        return array_merge($lower, $dimensions, [
            'date' => $date,
            'hour' => $hour === null ? 0 : max(0, min(23, (int) $hour)),
            'currency' => strtoupper((string) ($lower['currency'] ?? config('reporting.default_currency', 'USD'))),
            'ad_requests' => $requests,
            'matched_requests' => $matched,
            'unfilled_requests' => $unfilled,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'viewability_bp' => $viewabilityBp === null ? null : max(0, min(10000, $viewabilityBp)),
            'gross_revenue_minor' => $gross,
            'demand_partner_deductions_minor' => $this->signedInteger($lower['demand_partner_deductions_minor'] ?? 0),
            'invalid_traffic_adjustments_minor' => $this->signedInteger($lower['invalid_traffic_adjustments_minor'] ?? $lower['ivt_adjustments_minor'] ?? 0),
            'other_adjustments_minor' => $this->signedInteger($lower['other_adjustments_minor'] ?? 0),
            'video_starts' => $this->integer($lower['video_starts'] ?? $lower['video_viewership_start'] ?? 0),
            'completed_views' => $this->integer($lower['completed_views'] ?? $lower['video_viewership_complete'] ?? 0),
            'spend_minor' => array_key_exists('spend_minor', $lower) ? $this->signedInteger($lower['spend_minor']) : 0,
            'remaining_budget_minor' => $this->signedInteger($lower['remaining_budget_minor'] ?? 0),
        ]);
    }

    private function metricPayload(array $metrics): array
    {
        return collect($metrics)->only([
            'ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks',
            'fill_rate_bp', 'ctr_bp', 'viewability_bp', 'gross_revenue_minor',
            'demand_partner_deductions_minor', 'invalid_traffic_adjustments_minor',
            'other_adjustments_minor', 'net_revenue_minor', 'publisher_earnings_minor',
            'horus_earnings_minor', 'mcm_partner_earnings_minor', 'ecpm_micros',
            'cpc_micros', 'video_starts', 'completed_views',
        ])->all();
    }

    private function emptyTotals(): array
    {
        return array_fill_keys([
            'ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks',
            'gross_revenue_minor', 'demand_partner_deductions_minor',
            'invalid_traffic_adjustments_minor', 'other_adjustments_minor',
            'net_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor',
            'mcm_partner_earnings_minor', 'video_starts', 'completed_views',
        ], 0);
    }

    private function addTotals(array &$totals, array $metrics): void
    {
        foreach (array_keys($totals) as $field) {
            $totals[$field] += (int) ($metrics[$field] ?? 0);
        }
        $totals = array_merge($totals, $this->calculator->rates($totals));
    }

    private function discrepancyWarnings(array $source, array $stored): array
    {
        if ($source === []) {
            return [];
        }
        $warnings = [];
        $threshold = (int) config('reporting.discrepancy_warning_bp', 100);
        foreach (['impressions', 'clicks', 'gross_revenue_minor'] as $field) {
            if (! array_key_exists($field, $source)) {
                continue;
            }
            $expected = max(0, (int) $source[$field]);
            $actual = max(0, (int) ($stored[$field] ?? 0));
            $basisPoints = $expected > 0 ? (int) round(abs($actual - $expected) * 10000 / $expected) : ($actual === 0 ? 0 : 10000);
            if ($basisPoints > $threshold) {
                $warnings[] = [
                    'field' => $field, 'source' => $expected, 'stored' => $actual,
                    'difference_basis_points' => $basisPoints,
                ];
            }
        }
        return $warnings;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('The CSV report could not be opened.');
        }
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['report' => 'The CSV report has no header row.']);
            }
            $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) === count($headers)) {
                    $rows[] = array_combine($headers, $values);
                }
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function integer(mixed $value): int
    {
        return max(0, (int) str_replace([',', ' '], '', trim((string) $value)));
    }

    private function signedInteger(mixed $value): int
    {
        return (int) str_replace([',', ' '], '', trim((string) $value));
    }

    private function minorUnits(mixed $value): int
    {
        $normalized = str_replace([',', '$', '€', '£', 'EGP'], '', trim((string) $value));
        if ($normalized === '') {
            return 0;
        }
        return (int) round(((float) $normalized) * 100);
    }

    private function stable(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->stable($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    private function recordError(
        ReportSourceConnection $connection,
        ?ReportImportJob $job,
        string $category,
        string $code,
        string $message,
        bool $retryable,
    ): void {
        ReportError::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'report_source_connection_id' => $connection->id,
            'report_import_job_id' => $job?->id,
            'category' => $category,
            'code' => $code,
            'message' => mb_substr($message, 0, 10000),
            'retryable' => $retryable,
            'context' => ['connection_type' => $connection->connection_type],
            'occurred_at' => now(),
        ]);
    }
}
