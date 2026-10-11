<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MobileAppReportBinding extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $guarded = [];
    protected function casts(): array { return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date']; }
    public function connectionType(): string { return 'MOBILE_APP_GAM'; }
    public function mobileApp() { return $this->belongsTo(MobileApp::class); }
    public function gamConnection() { return $this->belongsTo(GamConnection::class); }
    public function connection() { return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id'); }
}
