<?php

use App\Services\Audit\AuditRecorder;
use App\Services\Settings\GlobalSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $key = 'video_player.mid_roll_interval_seconds';
            $row = DB::table('global_settings')->where('key', $key)->lockForUpdate()->first();
            if (! $row || json_decode($row->value, true) === 5) return;
            DB::table('global_settings')->where('key', $key)->update([
                'value' => '5', 'changed_by' => null, 'updated_at' => now(),
            ]);
            app(AuditRecorder::class)->record('video_player.interval_default.updated',
                oldValues: ['setting_key' => $key, 'value' => json_decode($row->value, true)],
                newValues: ['setting_key' => $key, 'value' => 5],
                metadata: ['reason' => 'Owner-requested five-second video interval for existing and future websites.'],
            );
        });
        app(GlobalSettingsService::class)->invalidate();
        // Deployment refreshes existing immutable Quick Monetize recipes.
    }

    public function down(): void
    {
        // Keep subsequent operator settings; prior runtimes safely bound this value.
    }
};
