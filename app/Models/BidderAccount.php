<?php

namespace App\Models;

use App\Enums\BidderAdsTxtRequirement;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BidderAccount extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'prebid_bidder_id', 'name', 'publisher_id', 'public_parameters', 'enabled',
        'ads_txt_requirement', 'ads_txt_evidence_url', 'ads_txt_requirement_verified_at',
        'ads_txt_requirement_reviewed_by', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'public_parameters' => 'array',
            'enabled' => 'boolean',
            'ads_txt_requirement' => BidderAdsTxtRequirement::class,
            'ads_txt_requirement_verified_at' => 'datetime',
        ];
    }

    public function bidder(): BelongsTo { return $this->belongsTo(PrebidBidder::class, 'prebid_bidder_id'); }
    public function siteMappings(): HasMany { return $this->hasMany(BidderSiteMapping::class); }
    public function adsTxtRecords(): HasMany { return $this->hasMany(BidderAdsTxtRecord::class); }
    public function adsTxtRequirementReviewer(): BelongsTo { return $this->belongsTo(User::class, 'ads_txt_requirement_reviewed_by'); }
    public function financialBinding(): HasOne { return $this->hasOne(MonetizationFinancialBinding::class, 'subject_id')->where('subject_type', 'BIDDER_ACCOUNT'); }
}
