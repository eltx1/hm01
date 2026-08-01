<?php

namespace App\Models;

use App\Enums\ConfigEnvironment;
use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaticDeliveryItem extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'config_version_id', 'batch_id', 'environment',
        'status', 'priority', 'checksum', 'attempts', 'available_at', 'delivered_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'environment' => ConfigEnvironment::class,
            'status' => StaticDeliveryStatus::class,
            'priority' => StaticDeliveryPriority::class,
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function configVersion(): BelongsTo { return $this->belongsTo(ConfigVersion::class); }
    public function batch(): BelongsTo { return $this->belongsTo(StaticDeliveryBatch::class, 'batch_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
