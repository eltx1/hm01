<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyDiagnosticEvidence extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'privacy_diagnostic_evidence';

    protected $fillable = [
        'organization_id', 'site_id', 'privacy_diagnostic_token_id', 'environment', 'result_status',
        'loader_version', 'config_version', 'hostname', 'tcf_api_detected', 'tcf_api_responded',
        'tcf_cmp_id', 'tcf_event_status', 'gpp_api_detected', 'gpp_api_responded',
        'gpp_applicable_sections', 'gpc_detected', 'configured_timeout_action', 'prebid_modules',
        'prebid_consent_configured', 'prebid_storage_control_configured',
        'prebid_activity_controls_configured', 'privacy_gate_respected', 'observed_at', 'result_hash',
    ];

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'tcf_api_detected' => 'boolean', 'tcf_api_responded' => 'boolean',
            'gpp_api_detected' => 'boolean', 'gpp_api_responded' => 'boolean',
            'gpp_applicable_sections' => 'array', 'gpc_detected' => 'boolean',
            'prebid_modules' => 'array', 'prebid_consent_configured' => 'boolean',
            'prebid_storage_control_configured' => 'boolean',
            'prebid_activity_controls_configured' => 'boolean',
            'privacy_gate_respected' => 'boolean', 'observed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PrivacyDiagnosticToken::class, 'privacy_diagnostic_token_id');
    }
}
