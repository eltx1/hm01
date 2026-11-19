<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nonfinancial per-format Google delivery evidence for one website day. */
class SiteGamAdFormatReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    // Store SQL DATE identically on SQLite and MySQL.
    public function setReportDateAttribute(mixed $value): void
    {
        $this->attributes['report_date'] = \Carbon\CarbonImmutable::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date', 'reported_at' => 'immutable_datetime',
            'ad_requests' => 'integer', 'matched_requests' => 'integer', 'impressions' => 'integer', 'clicks' => 'integer',
            'active_view_viewable_impressions' => 'integer', 'active_view_measurable_impressions' => 'integer',
            'revenue_micros' => 'integer',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function connection(): BelongsTo { return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id'); }
}
