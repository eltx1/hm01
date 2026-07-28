<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidGamTemplate extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'prebid_price_bucket_id', 'name', 'enabled',
        'advertiser_name', 'order_prefix', 'line_item_prefix', 'creative_prefix', 'targeting_keys',
        'targeting_values', 'creative_sizes', 'max_line_items_per_order', 'priority',
        'currency_code', 'universal_creative_snippet', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'targeting_keys' => 'array', 'targeting_values' => 'array',
            'creative_sizes' => 'array', 'max_line_items_per_order' => 'integer', 'priority' => 'integer',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function priceBucket(): BelongsTo { return $this->belongsTo(PrebidPriceBucket::class, 'prebid_price_bucket_id'); }
    public function runs(): HasMany { return $this->hasMany(PrebidSetupRun::class); }
}
