<?php

namespace App\Models;

use App\Enums\CampaignCreativeStatus;
use App\Enums\CampaignCreativeType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignCreative extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'campaign_id', 'replaces_creative_id', 'name', 'type', 'status', 'width', 'height', 'landing_url', 'click_through_url', 'html_content', 'vast_url', 'native_assets', 'text_content', 'is_active', 'reviewed_by', 'reviewed_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['type' => CampaignCreativeType::class, 'status' => CampaignCreativeStatus::class, 'native_assets' => 'array', 'is_active' => 'boolean', 'reviewed_at' => 'datetime'];
    }

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function files(): HasMany { return $this->hasMany(CreativeFile::class); }
    public function replaces(): BelongsTo { return $this->belongsTo(self::class, 'replaces_creative_id'); }
}
