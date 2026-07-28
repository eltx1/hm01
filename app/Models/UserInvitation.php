<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'role_id', 'invited_by', 'email', 'token_hash', 'expires_at', 'accepted_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return ! $this->accepted_at && $this->expires_at->isFuture();
    }
}
