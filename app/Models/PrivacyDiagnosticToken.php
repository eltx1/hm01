<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrivacyDiagnosticToken extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id', 'environment', 'token_hash', 'allowed_hostnames', 'max_reports',
        'report_count', 'created_by', 'expires_at', 'last_used_at', 'completed_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'allowed_hostnames' => 'array',
            'max_reports' => 'integer',
            'report_count' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(PrivacyDiagnosticEvidence::class);
    }
}
