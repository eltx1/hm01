<?php

namespace App\Models;

use App\Enums\ReconciliationStatus;
use App\Enums\ReportGranularity;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationRun extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'report_source_connection_id', 'report_import_job_id',
        'period_start', 'period_end', 'granularity', 'status', 'source_totals',
        'stored_totals', 'differences', 'discrepancy_basis_points', 'warnings',
        'error_message', 'started_at', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime', 'period_end' => 'datetime',
            'granularity' => ReportGranularity::class, 'status' => ReconciliationStatus::class,
            'source_totals' => 'array', 'stored_totals' => 'array',
            'differences' => 'array', 'warnings' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id'); }
    public function import(): BelongsTo { return $this->belongsTo(ReportImportJob::class, 'report_import_job_id'); }
}
