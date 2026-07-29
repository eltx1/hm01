<?php

namespace App\Models;

use App\Enums\ReportConnectionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSourceConnection extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'report_source_id', 'name', 'connection_type', 'connection_id',
        'account_identifier', 'currency', 'timezone', 'status', 'is_enabled', 'configuration',
        'last_attempted_at', 'last_successful_import_at', 'last_finalized_import_at',
        'last_error', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportConnectionStatus::class,
            'is_enabled' => 'boolean',
            'configuration' => 'array',
            'last_attempted_at' => 'datetime',
            'last_successful_import_at' => 'datetime',
            'last_finalized_import_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReportSource::class, 'report_source_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ReportImportJob::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ReportError::class);
    }
}
