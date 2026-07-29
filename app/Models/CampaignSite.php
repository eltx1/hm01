<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignSite extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_id', 'publisher_id', 'site_id', 'budget_weight', 'is_active'];
    protected function casts(): array { return ['budget_weight' => 'decimal:4', 'is_active' => 'boolean']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
}
