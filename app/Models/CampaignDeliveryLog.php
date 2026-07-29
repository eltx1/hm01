<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDeliveryLog extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_id', 'campaign_network_instance_id', 'report_date', 'source', 'external_report_id', 'impressions', 'clicks', 'views', 'spend_minor', 'dimensions', 'imported_at'];
    protected function casts(): array { return ['report_date' => 'date', 'dimensions' => 'array', 'imported_at' => 'datetime']; }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function networkInstance(): BelongsTo { return $this->belongsTo(CampaignNetworkInstance::class, 'campaign_network_instance_id'); }
}
