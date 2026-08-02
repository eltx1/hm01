<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupplyChainCheck extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'site_id', 'check_type', 'status', 'url', 'http_status', 'checksum', 'findings', 'checked_at'];
    protected function casts(): array { return ['findings' => 'array', 'checked_at' => 'datetime']; }
}
