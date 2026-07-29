<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativeFile extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_creative_id', 'disk', 'path', 'original_name', 'mime_type', 'extension', 'size_bytes', 'sha256', 'width', 'height', 'asset_manifest'];
    protected function casts(): array { return ['asset_manifest' => 'array']; }
    public function creative(): BelongsTo { return $this->belongsTo(CampaignCreative::class, 'campaign_creative_id'); }
}
