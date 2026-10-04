<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SiteGamUnfilledReport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    // Store SQL DATE identically on SQLite and MySQL. Eloquent's generic
    // date cast otherwise serializes midnight as a datetime in SQLite.
    public function setReportDateAttribute(mixed $value): void
    {
        $this->attributes['report_date'] = \Carbon\CarbonImmutable::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return ['report_date' => 'immutable_date', 'reported_at' => 'immutable_datetime', 'unfilled_impressions' => 'integer'];
    }
}
