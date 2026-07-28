<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidderPlacementMapping extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'bidder_account_id', 'placement_id', 'gam_connection_id',
        'gam_connection_key', 'enabled', 'public_parameters',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'public_parameters' => 'array'];
    }

    public function account(): BelongsTo { return $this->belongsTo(BidderAccount::class, 'bidder_account_id'); }
    public function placement(): BelongsTo { return $this->belongsTo(Placement::class); }
    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
}
