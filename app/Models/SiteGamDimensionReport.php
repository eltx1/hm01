<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nonfinancial per-country or per-device Google delivery evidence for one website day. */
class SiteGamDimensionReport extends Model
{
    use BelongsToOrganization, HasUlids;

    public const COUNTRY = 'COUNTRY';

    public const DEVICE = 'DEVICE';

    protected $guarded = [];

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

    /** Uniform breakdown accessors shared with SiteGamAdFormatReport. */
    public function getBreakdownKeyAttribute(): string { return $this->dimension_key; }
    public function getBreakdownNameAttribute(): ?string { return $this->dimension_name; }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
}
