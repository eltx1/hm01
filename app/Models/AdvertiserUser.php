<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertiserUser extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'advertiser_id', 'user_id', 'role', 'is_primary'];

    protected function casts(): array { return ['is_primary' => 'boolean']; }

    public function advertiser(): BelongsTo { return $this->belongsTo(Advertiser::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
