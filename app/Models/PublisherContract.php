<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PublisherContract extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'publisher_id', 'contract_reference', 'starts_at', 'ends_at', 'auto_renews', 'revenue_share_percent', 'payment_threshold', 'currency', 'payment_terms', 'contract_file_path', 'contract_file_name', 'contract_file_mime', 'status', 'internal_notes', 'created_by'];

    protected $hidden = ['internal_notes', 'contract_file_path'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date', 'ends_at' => 'date', 'auto_renews' => 'boolean',
            'revenue_share_percent' => 'decimal:2', 'payment_threshold' => 'decimal:2',
            'status' => ContractStatus::class,
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
