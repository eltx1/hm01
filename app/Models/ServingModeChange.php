<?php

namespace App\Models;

use App\Enums\ServingMode;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServingModeChange extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'site_id', 'previous_mode', 'new_mode', 'administrator_id', 'reason', 'rollback_reference_id', 'created_at'];

    protected function casts(): array
    {
        return ['previous_mode' => ServingMode::class, 'new_mode' => ServingMode::class, 'created_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
