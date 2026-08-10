<?php

namespace App\Models;

use App\Enums\SupportTicketPriority;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportSlaPolicy extends Model
{
    use HasUlids;

    protected $fillable = [
        'name', 'priority', 'first_response_minutes', 'resolution_minutes',
        'warning_before_minutes', 'pause_while_waiting_on_customer', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => SupportTicketPriority::class,
            'pause_while_waiting_on_customer' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'sla_policy_id');
    }
}
