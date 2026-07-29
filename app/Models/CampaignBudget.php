<?php

namespace App\Models;

use App\Enums\CampaignPricingModel;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignBudget extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_id', 'pricing_model', 'currency', 'total_minor', 'daily_minor', 'unit_price_minor', 'allocated_minor', 'spent_minor', 'bonus_units', 'bonus_note'];
    protected function casts(): array { return ['pricing_model' => CampaignPricingModel::class]; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
