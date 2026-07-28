<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteLayoutProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'source_site_id', 'name', 'description',
        'snapshot', 'is_default', 'created_by',
    ];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'is_default' => 'boolean'];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function sourceSite(): BelongsTo { return $this->belongsTo(Site::class, 'source_site_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
