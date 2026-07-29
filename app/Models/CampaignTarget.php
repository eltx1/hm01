<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTarget extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_id', 'dimension', 'operator', 'values'];
    protected function casts(): array { return ['values' => 'array']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
