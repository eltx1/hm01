<?php

namespace App\Services\Demand;

use App\Enums\DemandReportImportStatus;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandError;
use App\Models\DemandReportImport;
use App\Models\DemandSite;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Reporting\ReportingBridge;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class DemandReportService
{
    public function __construct(
        private readonly DemandConnectorManager $connectors,
        private readonly AuditRecorder $audit,
        private readonly ReportingBridge $reportingBridge,
    ) {
    }

    public function runApi(
        DemandAccount $account,
        CarbonInterface $from,
        CarbonInterface $to,
        User $actor,
        array $filters = [],
    ): DemandReportImport {
        $import = DemandReportImport::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'import_type' => 'API',
            'status' => DemandReportImportStatus::Processing,
            'period_start' => $from,
            'period_end' => $to,
            'created_by' => $actor->id,
        ]);

        $result = $this->connectors->for($account)->runReport($from, $to, ['filters' => $filters]);
        if (! $result->success) {
            $import->update([
                'status' => DemandReportImportStatus::Failed,
                'error_message' => $result->errorMessage,
            ]);
            DemandError::withoutGlobalScopes()->create([
                'organization_id' => $account->organization_id,
                'demand_account_id' => $account->id,
                'category' => $result->errorCategory ?: 'REPORT',
                'code' => $result->errorCode,
                'message' => $result->errorMessage ?: 'Demand report import failed.',
                'retryable' => $result->retryable,
                'context' => ['import_id' => $import->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
                'occurred_at' => now(),
            ]);

            return $import->refresh();
        }

        $rows = $this->normalizeRows((array) (data_get($result->data, 'rows') ?? data_get($result->data, 'data') ?? []));
        $import->update([
            'status' => DemandReportImportStatus::Completed,
            'external_report_id' => data_get($result->data, 'report_id'),
            'row_count' => count($rows),
            'normalized_rows' => $rows,
            'totals' => $this->totals($rows),
            'imported_at' => now(),
        ]);

        $this->audit->record('demand.report.api_imported', $account->organization_id, $actor, $import, newValues: [
            'row_count' => count($rows),
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
        ]);
        $this->reportingBridge->recordDemandRows(
            $account,
            $rows,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $actor,
            null,
            (string) ($import->external_report_id ?: 'demand-api:'.$import->id),
            'DEMAND_API_BRIDGE',
        );

        return $import->refresh();
    }

    public function importCsv(
        DemandAccount $account,
        UploadedFile $file,
        CarbonInterface $from,
        CarbonInterface $to,
        User $actor,
        ?DemandSite $site = null,
    ): DemandReportImport {
        if (! $file->isValid() || $file->getSize() > (int) config('demand.csv_max_bytes', 20 * 1024 * 1024)) {
            throw ValidationException::withMessages(['report' => 'The CSV report is invalid or exceeds the configured maximum size.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['report' => 'Demand report fallback accepts CSV files only.']);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = DemandReportImport::withoutGlobalScopes()
            ->where('demand_account_id', $account->id)
            ->where('checksum', $checksum)
            ->where('status', DemandReportImportStatus::Completed->value)
            ->first();
        if ($existing) {
            return $existing;
        }

        $rows = $this->readCsv($file->getRealPath());
        $path = $file->storeAs(
            'demand-reports/'.$account->id,
            now()->format('YmdHis').'-'.$checksum.'.csv',
            'local',
        );

        $import = DemandReportImport::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $site?->site_id,
            'import_type' => 'CSV',
            'status' => DemandReportImportStatus::Completed,
            'period_start' => $from,
            'period_end' => $to,
            'source_file_path' => $path,
            'checksum' => $checksum,
            'row_count' => count($rows),
            'normalized_rows' => $rows,
            'totals' => $this->totals($rows),
            'imported_at' => now(),
            'created_by' => $actor->id,
        ]);

        $this->audit->record('demand.report.csv_imported', $account->organization_id, $actor, $import, newValues: [
            'row_count' => count($rows),
            'checksum' => $checksum,
        ]);
        $this->reportingBridge->recordDemandRows(
            $account,
            $rows,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $actor,
            $site,
            'demand-csv:'.$checksum,
            'DEMAND_CSV_BRIDGE',
        );

        return $import;
    }

    public function syncAdsTxt(DemandAccount $account, ?DemandSite $site, User $actor): int
    {
        $records = $this->connectors->for($account)->getAdsTxtRecords($site);
        $hashes = collect($records)->pluck('record_hash')->filter()->values();
        DemandAdsTxtRecord::withoutGlobalScopes()
            ->where('demand_account_id', $account->id)
            ->when($site, fn ($query) => $query->where('site_id', $site->site_id), fn ($query) => $query->whereNull('site_id'))
            ->when($hashes->isNotEmpty(), fn ($query) => $query->whereNotIn('record_hash', $hashes))
            ->update(['status' => 'REMOVED']);

        foreach ($records as $record) {
            DemandAdsTxtRecord::withoutGlobalScopes()->updateOrCreate(
                [
                    'demand_account_id' => $account->id,
                    'site_id' => $site?->site_id,
                    'record_hash' => $record['record_hash'],
                ],
                [
                    'organization_id' => $account->organization_id,
                    'domain' => $record['domain'],
                    'publisher_account_id' => $record['publisher_account_id'],
                    'relationship' => $record['relationship'],
                    'certification_authority_id' => $record['certification_authority_id'],
                    'raw_record' => $record['raw_record'],
                    'status' => 'ACTIVE',
                    'source' => 'CONNECTOR',
                    'last_verified_at' => now(),
                ],
            );
        }

        $this->audit->record('demand.ads_txt.synchronized', $account->organization_id, $actor, $account, newValues: [
            'site_id' => $site?->site_id,
            'records' => count($records),
        ]);

        return count($records);
    }

    public function summary(DemandAccount $account): array
    {
        $imports = DemandReportImport::withoutGlobalScopes()
            ->where('demand_account_id', $account->id)
            ->where('status', DemandReportImportStatus::Completed->value)
            ->get();

        return [
            'imports' => $imports->count(),
            'impressions' => $imports->sum(fn ($import) => (int) data_get($import->totals, 'impressions', 0)),
            'clicks' => $imports->sum(fn ($import) => (int) data_get($import->totals, 'clicks', 0)),
            'revenue_minor' => $imports->sum(fn ($import) => (int) data_get($import->totals, 'revenue_minor', 0)),
        ];
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('The uploaded demand CSV could not be opened.');
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['report' => 'The demand CSV has no header row.']);
            }
            $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($headers)) {
                    continue;
                }
                $rows[] = array_combine($headers, $values);
            }
        } finally {
            fclose($handle);
        }

        return $this->normalizeRows($rows);
    }

    private function normalizeRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row): array {
                $date = $row['date'] ?? $row['day'] ?? $row['report_date'] ?? null;
                $impressions = $row['impressions'] ?? $row['views'] ?? 0;
                $clicks = $row['clicks'] ?? 0;
                $hasMinorRevenue = array_key_exists('revenue_minor', $row);
                $revenue = $hasMinorRevenue ? $row['revenue_minor'] : ($row['revenue'] ?? $row['earnings'] ?? 0);
                $timestamp = $date ? strtotime((string) $date) : false;

                return [
                    'date' => $timestamp !== false ? date('Y-m-d', $timestamp) : null,
                    'site' => (string) ($row['site'] ?? $row['domain'] ?? ''),
                    'placement' => (string) ($row['placement'] ?? $row['widget'] ?? $row['widget_id'] ?? ''),
                    'impressions' => max(0, (int) str_replace(',', '', (string) $impressions)),
                    'clicks' => max(0, (int) str_replace(',', '', (string) $clicks)),
                    'revenue_minor' => $hasMinorRevenue
                        ? max(0, (int) str_replace(',', '', (string) $revenue))
                        : $this->minorUnits($revenue),
                ];
            })
            ->filter(fn (array $row) => $row['date'] !== null)
            ->values()
            ->all();
    }

    private function totals(array $rows): array
    {
        return [
            'impressions' => array_sum(array_column($rows, 'impressions')),
            'clicks' => array_sum(array_column($rows, 'clicks')),
            'revenue_minor' => array_sum(array_column($rows, 'revenue_minor')),
        ];
    }

    private function minorUnits(mixed $value): int
    {
        $value = str_replace([',', '$', '€', '£'], '', trim((string) $value));
        if ($value === '') {
            return 0;
        }

        return str_contains($value, '.')
            ? max(0, (int) round(((float) $value) * 100))
            : max(0, (int) $value);
    }
}
