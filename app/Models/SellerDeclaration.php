<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SellerDeclaration extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'site_id', 'seller_id', 'seller_type', 'name', 'domain', 'is_confidential', 'status', 'last_verified_at', 'metadata'];
    protected function casts(): array { return ['is_confidential' => 'boolean', 'last_verified_at' => 'datetime', 'metadata' => 'array']; }
}
