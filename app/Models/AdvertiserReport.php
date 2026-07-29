<?php

namespace App\Models;

use App\Enums\ReportFinality;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertiserReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['report_date' => 'date', 'finality' => ReportFinality::class];
    }

    public function advertiser(): BelongsTo { return $this->belongsTo(Advertiser::class); }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function dimension(): BelongsTo { return $this->belongsTo(ReportDimension::class, 'report_dimension_id'); }
    public function connection(): BelongsTo { return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id'); }
}
