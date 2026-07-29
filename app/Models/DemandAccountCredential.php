<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandAccountCredential extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'credential_key', 'reference',
        'hint', 'capability', 'expires_at', 'rotated_at', 'metadata',
    ];

    protected $hidden = ['reference'];

    protected function casts(): array
    {
        return [
            'reference' => 'encrypted',
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DemandAccount::class, 'demand_account_id');
    }
}
