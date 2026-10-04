<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteGamReportBinding extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    public function connectionType(): string { return 'SITE_GAM_AD_UNIT'; }

    public static function modelForConnection(ReportSourceConnection $connection): string
    {
        return match ($connection->connection_type) {
            'SITE_GAM_AD_UNIT' => self::class,
            'SITE_GAM_VIDEO_AD_UNIT' => SiteGamVideoReportBinding::class,
            default => throw new \InvalidArgumentException('Not a website GAM report connection.'),
        };
    }

    public static function isSiteConnection(ReportSourceConnection $connection): bool
    {
        return in_array($connection->connection_type, ['SITE_GAM_AD_UNIT', 'SITE_GAM_VIDEO_AD_UNIT'], true);
    }

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function gamConnection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id');
    }
}
