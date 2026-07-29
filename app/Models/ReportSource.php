<?php

namespace App\Models;

use App\Enums\ReportSourceCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSource extends Model
{
    use HasUlids;

    protected $fillable = [
        'code', 'name', 'connector_class', 'is_primary', 'is_enabled', 'capabilities', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'code' => ReportSourceCode::class,
            'is_primary' => 'boolean',
            'is_enabled' => 'boolean',
            'capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(ReportSourceConnection::class);
    }
}
