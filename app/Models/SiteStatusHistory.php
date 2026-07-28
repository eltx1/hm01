<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteStatusHistory extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'site_status_history';

    protected $fillable = ['organization_id', 'site_id', 'previous_status', 'new_status', 'changed_by', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['previous_status' => SiteStatus::class, 'new_status' => SiteStatus::class, 'created_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
