<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignPlacement extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_id', 'campaign_site_id', 'placement_id', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function campaignSite(): BelongsTo { return $this->belongsTo(CampaignSite::class); }
    public function placement(): BelongsTo { return $this->belongsTo(Placement::class); }
}
