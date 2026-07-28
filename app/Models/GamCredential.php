<?php

namespace App\Models;

use App\Enums\GamCredentialType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamCredential extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'credential_type', 'reference',
        'client_email_hint', 'oauth_client_id_hint', 'scopes', 'expires_at', 'rotated_at', 'metadata',
    ];

    protected $hidden = ['reference'];

    protected function casts(): array
    {
        return [
            'credential_type' => GamCredentialType::class,
            'reference' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }
}
