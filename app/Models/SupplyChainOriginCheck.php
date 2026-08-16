<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupplyChainOriginCheck extends Model
{
    use HasUlids;

    protected $fillable = [
        'artifact', 'canonical_url', 'final_url', 'status', 'http_status', 'content_type',
        'payload_sha256', 'error_code', 'error_message', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
