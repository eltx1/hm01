<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrebidGamTemplate extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'gam_connection_id', 'name', 'advertiser_name', 'order_name_prefix', 'line_item_name_template', 'creative_name', 'targeting', 'creative_snippet', 'enabled'];

    protected function casts(): array
    {
        return ['targeting' => 'array', 'enabled' => 'boolean'];
    }
}
