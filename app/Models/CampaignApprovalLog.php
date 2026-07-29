<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignApprovalLog extends Model
{
    use BelongsToOrganization, HasUlids;
    public $timestamps = false;
    protected $fillable = ['organization_id', 'campaign_id', 'campaign_creative_id', 'actor_id', 'action', 'from_status', 'to_status', 'reason', 'metadata', 'created_at'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function creative(): BelongsTo { return $this->belongsTo(CampaignCreative::class, 'campaign_creative_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
