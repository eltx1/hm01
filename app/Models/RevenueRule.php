<?php

namespace App\Models;

use App\Enums\RevenueRuleScope;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RevenueRule extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'scope_type', 'scope_id', 'is_active', 'effective_from',
        'effective_to', 'priority', 'current_version_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => RevenueRuleScope::class,
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RevenueRuleVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RevenueRuleVersion::class, 'current_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
