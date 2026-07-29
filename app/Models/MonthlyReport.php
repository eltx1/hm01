<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    public function connection(): BelongsTo { return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id'); }
    public function period(): BelongsTo { return $this->belongsTo(FinancialPeriod::class, 'financial_period_id'); }
    public function dimension(): BelongsTo { return $this->belongsTo(ReportDimension::class, 'report_dimension_id'); }
    public function revenueRuleVersion(): BelongsTo { return $this->belongsTo(RevenueRuleVersion::class); }
}
