<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    use HasUlids;

    protected $fillable = [
        'base_currency', 'quote_currency', 'rate_date', 'rate', 'source', 'checksum', 'created_by',
    ];

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'rate' => 'decimal:10'];
    }
}
