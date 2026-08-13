<?php

namespace App\Models;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportImportJob extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'report_source_connection_id', 'financial_period_id', 'import_type',
        'granularity', 'finality', 'status', 'period_start', 'period_end', 'external_report_id',
        'settlement_eligible', 'settlement_ineligibility_reason',
        'idempotency_key', 'checksum', 'attempt_count', 'row_count', 'inserted_count',
        'updated_count', 'duplicate_count', 'source_totals', 'normalized_totals', 'warnings',
        'error_message', 'started_at', 'completed_at', 'next_retry_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'granularity' => ReportGranularity::class,
            'finality' => ReportFinality::class,
            'settlement_eligible' => 'boolean',
            'status' => ReportImportStatus::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'source_totals' => 'array',
            'normalized_totals' => 'array',
            'warnings' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportImportFile::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(ReconciliationRun::class, 'report_import_job_id');
    }
}
