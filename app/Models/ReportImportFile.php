<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportImportFile extends Model
{
    use HasUlids;

    protected $fillable = [
        'report_import_job_id', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'checksum', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ReportImportJob::class, 'report_import_job_id');
    }
}
