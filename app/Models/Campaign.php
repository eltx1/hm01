<?php

namespace App\Models;

use App\Enums\CampaignPricingModel;
use App\Enums\CampaignStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['public_key', 'organization_id', 'advertiser_id', 'name', 'objective', 'pricing_model', 'status', 'starts_at', 'ends_at', 'currency', 'total_budget_minor', 'daily_budget_minor', 'frequency_cap_impressions', 'frequency_cap_days', 'landing_url', 'advertiser_notes', 'internal_notes', 'created_by', 'updated_by', 'submitted_at', 'approved_at', 'scheduled_at', 'activated_at', 'paused_at', 'completed_at'];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'pricing_model' => CampaignPricingModel::class,
            'status' => CampaignStatus::class,
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'submitted_at' => 'datetime',
            'approved_at' => 'datetime', 'scheduled_at' => 'datetime', 'activated_at' => 'datetime',
            'paused_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function advertiser(): BelongsTo { return $this->belongsTo(Advertiser::class); }
    public function goals(): HasMany { return $this->hasMany(CampaignGoal::class); }
    public function targets(): HasMany { return $this->hasMany(CampaignTarget::class); }
    public function sites(): HasMany { return $this->hasMany(CampaignSite::class); }
    public function placements(): HasMany { return $this->hasMany(CampaignPlacement::class); }
    public function creatives(): HasMany { return $this->hasMany(CampaignCreative::class); }
    public function budget(): HasOne { return $this->hasOne(CampaignBudget::class); }
    public function networkInstances(): HasMany { return $this->hasMany(CampaignNetworkInstance::class); }
    public function deliveryLogs(): HasMany { return $this->hasMany(CampaignDeliveryLog::class); }
    public function approvalLogs(): HasMany { return $this->hasMany(CampaignApprovalLog::class); }
    public function invoices(): HasMany { return $this->hasMany(AdvertiserInvoice::class); }
}
