<?php

namespace App\Models;

use App\Enums\FinancialReportingMethod;
use App\Enums\MonetizationSubjectType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonetizationFinancialBinding extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'subject_type', 'subject_id', 'report_source_id',
        'report_source_connection_id', 'reporting_method', 'currency', 'timezone',
        'is_enabled', 'is_finalized_capable', 'effective_from', 'effective_to',
        'configuration', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => MonetizationSubjectType::class,
            'reporting_method' => FinancialReportingMethod::class,
            'is_enabled' => 'boolean',
            'is_finalized_capable' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'configuration' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReportSource::class, 'report_source_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id');
    }
}
