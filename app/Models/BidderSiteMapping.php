<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidderSiteMapping extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'bidder_account_id', 'site_id', 'gam_connection_id',
        'gam_connection_key', 'enabled', 'sequence', 'public_parameters',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sequence' => 'integer', 'public_parameters' => 'array'];
    }

    public function account(): BelongsTo { return $this->belongsTo(BidderAccount::class, 'bidder_account_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
}
