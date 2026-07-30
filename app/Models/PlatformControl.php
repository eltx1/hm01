<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformControl extends Model
{
    use HasUlids;

    protected $fillable = ['scope_type', 'scope_id', 'control_key', 'is_disabled', 'reason', 'metadata', 'changed_by', 'changed_at'];

    protected function casts(): array
    {
        return ['is_disabled' => 'boolean', 'metadata' => 'array', 'changed_at' => 'datetime'];
    }

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
