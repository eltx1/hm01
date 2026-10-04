<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SiteGamUnfilledReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['report_date' => 'immutable_date', 'reported_at' => 'immutable_datetime', 'unfilled_impressions' => 'integer'];
    }
}
