<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;

class HorusNotification extends Model
{
    use HasUlids;

    protected $fillable = [
        'recipient_id', 'organization_id', 'category', 'type', 'severity', 'title',
        'message', 'related_type', 'related_id', 'action_route', 'action_parameters',
        'dedupe_key', 'in_app_visible', 'email_requested', 'email_attempts', 'emailed_at',
        'email_failed_at', 'read_at',
    ];

    protected $hidden = ['dedupe_key'];

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'severity' => NotificationSeverity::class,
            'action_parameters' => 'array',
            'in_app_visible' => 'boolean',
            'email_requested' => 'boolean',
            'emailed_at' => 'datetime',
            'email_failed_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function actionUrl(): ?string
    {
        if (! $this->action_route || ! Route::has($this->action_route)) {
            return null;
        }

        try {
            return route($this->action_route, $this->action_parameters ?? []);
        } catch (\Throwable) {
            return null;
        }
    }
}
