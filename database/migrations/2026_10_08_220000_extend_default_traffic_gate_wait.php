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
            $key = 'traffic_gate.max_wait_ms';
            $row = DB::table('global_settings')->where('key', $key)->lockForUpdate()->first();
            // Only upgrade the previous default. Preserve every custom value.
            if (! $row || ! in_array(json_decode($row->value, true), [10000, '10000'], true)) {
                return;
            }
            DB::table('global_settings')->where('key', $key)->update([
                'value' => json_encode(15000, JSON_THROW_ON_ERROR),
                'changed_by' => null,
                'updated_at' => now(),
            ]);
            app(AuditRecorder::class)->record('traffic_gate.timings_changed',
                oldValues: ['setting_key' => $key, 'value' => 10000],
                newValues: ['setting_key' => $key, 'value' => 15000],
                metadata: ['reason' => 'Extend the previous verification deadline to 15 seconds.',
                    'migration' => '2026_10_08_220000_extend_default_traffic_gate_wait', 'server_verified' => true],
            );
        });
        app(GlobalSettingsService::class)->invalidate();
        // The normal post-deploy traffic-gate:refresh-configs reconciliation
        // republishes changed static contracts with their original audit actor.
    }

    public function down(): void
    {
        // Do not overwrite a subsequently reviewed operator setting on rollback.
    }
};
