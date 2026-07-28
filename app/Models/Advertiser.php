<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Advertiser extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'legal_name', 'display_name', 'status', 'billing_email', 'logo_path', 'dashboard_title', 'primary_color', 'internal_notes'];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class];
    }
}
