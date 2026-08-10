<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'type', 'status', 'dashboard_title', 'logo_path', 'primary_color', 'support_email', 'internal_notes'];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return ['type' => OrganizationType::class, 'status' => AccountStatus::class];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function publisher(): HasOne
    {
        return $this->hasOne(Publisher::class);
    }

    public function advertiser(): HasOne
    {
        return $this->hasOne(Advertiser::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }
}
