<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change only the old generated preset default; retain custom limits.
        DB::table('placements')->where('type', 'REWARDED')->whereNull('deleted_at')
            ->orderBy('id')->chunkById(100, function ($placements): void {
                foreach ($placements as $placement) {
                    $metadata = json_decode($placement->metadata ?? '{}', true) ?: [];
                    $settings = json_decode($placement->format_settings ?? '{}', true) ?: [];
                    if (($metadata['placement_preset'] ?? null) !== 'rewarded'
                        || (int) ($settings['rewardCooldownSeconds'] ?? 0) !== 900) continue;
                    $settings['rewardCooldownSeconds'] = 60;
                    DB::table('placements')->where('id', $placement->id)
                        ->where('organization_id', $placement->organization_id)
                        ->update(['format_settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
            });
        $format = DB::table('ad_formats')->where('code', 'rewarded')->first();
        if ($format) {
            $defaults = json_decode($format->defaults ?? '{}', true) ?: [];
            if ((int) ($defaults['rewardCooldownSeconds'] ?? 0) === 900) {
                $defaults['rewardCooldownSeconds'] = 60;
                DB::table('ad_formats')->where('id', $format->id)->update([
                    'defaults' => json_encode($defaults, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Do not overwrite publisher settings on rollback.
    }
};
