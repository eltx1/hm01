<?php

namespace App\Models;

use App\Enums\SupportTicketEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['support_ticket_id', 'actor_id', 'event', 'from_value', 'to_value', 'metadata'];

    protected function casts(): array
    {
        return ['event' => SupportTicketEventType::class, 'metadata' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
