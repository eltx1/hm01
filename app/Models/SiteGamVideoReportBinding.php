<?php

namespace App\Models;

/** Independent financial/reporting identity; never changes the serving or primary binding. */
class SiteGamVideoReportBinding extends SiteGamReportBinding
{
    protected $table = 'site_gam_video_report_bindings';

    protected function casts(): array
    {
        return [...parent::casts(), 'cancelled_at' => 'immutable_datetime'];
    }

    public function connectionType(): string { return 'SITE_GAM_VIDEO_AD_UNIT'; }
}
