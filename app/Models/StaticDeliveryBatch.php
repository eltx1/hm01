<?php

namespace App\Models;

use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaticDeliveryBatch extends Model
{
    use HasUlids;

    protected $fillable = [
        'status', 'priority', 'trigger', 'driver', 'manifest_hash', 'is_deduplicated', 'item_count', 'file_count',
        'total_bytes', 'attempts', 'remote_deployment_id', 'remote_url',
        'provider_metadata', 'error_code', 'error_message', 'started_at',
        'submitted_at', 'deployed_at', 'next_retry_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => StaticDeliveryStatus::class,
            'priority' => StaticDeliveryPriority::class,
            'is_deduplicated' => 'boolean',
            'provider_metadata' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'deployed_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StaticDeliveryItem::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
