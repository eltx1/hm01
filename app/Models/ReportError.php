<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportError extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'report_source_connection_id', 'report_import_job_id',
        'category', 'code', 'message', 'retryable', 'context', 'occurred_at',
        'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'retryable' => 'boolean', 'context' => 'array',
            'occurred_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id'); }
    public function import(): BelongsTo { return $this->belongsTo(ReportImportJob::class, 'report_import_job_id'); }
}
