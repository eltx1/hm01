<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueRuleVersion extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'revenue_rule_id', 'version', 'publisher_share_bp', 'horus_share_bp',
        'mcm_partner_share_bp', 'effective_from', 'effective_to', 'currency',
        'reason', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'created_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RevenueRule::class, 'revenue_rule_id');
    }
}
