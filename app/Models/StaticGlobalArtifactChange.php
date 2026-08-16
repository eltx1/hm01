<?php

namespace App\Models;

use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaticGlobalArtifactChange extends Model
{
    use HasUlids;

    public const SUPPLY_CHAIN = 'SUPPLY_CHAIN';

    protected $fillable = [
        'artifact_type', 'batch_id', 'status', 'priority', 'event_count', 'context',
        'attempts', 'available_at', 'delivered_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => StaticDeliveryStatus::class,
            'priority' => StaticDeliveryPriority::class,
            'context' => 'array',
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StaticDeliveryBatch::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
