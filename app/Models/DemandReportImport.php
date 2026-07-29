<?php

namespace App\Models;

use App\Enums\DemandReportImportStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandReportImport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'site_id', 'import_type',
        'status', 'period_start', 'period_end', 'external_report_id',
        'source_file_path', 'checksum', 'row_count', 'normalized_rows',
        'totals', 'error_message', 'imported_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemandReportImportStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'normalized_rows' => 'array',
            'totals' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
