<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('placements')->where('type', 'DISPLAY')->whereNull('deleted_at')
            ->select(['id', 'metadata'])->orderBy('id')->chunkById(200, function ($placements): void {
                foreach ($placements as $placement) {
                    $metadata = json_decode($placement->metadata ?? '{}', true);
                    if (($metadata['placement_preset'] ?? null) !== 'responsive_display') continue;

                    DB::transaction(function () use ($placement): void {
                        // Follow the existing 300x250 viewport boundaries; never
                        // reset custom mappings or reactivate disabled size rows.
                        $templates = DB::table('placement_sizes')->where('placement_id', $placement->id)
                            ->where('size_type', 'FIXED')->where('width', 300)->where('height', 250)
                            ->where('is_active', true)->get();
                        $priority = (int) DB::table('placement_sizes')->where('placement_id', $placement->id)->max('priority');
                        foreach ($templates as $template) {
                            foreach ([[240, 400], [250, 360]] as [$width, $height]) {
                                $identity = ['placement_id' => $placement->id, 'size_type' => 'FIXED',
                                    'width' => $width, 'height' => $height, 'device' => $template->device,
                                    'min_viewport_width' => $template->min_viewport_width,
                                    'min_viewport_height' => $template->min_viewport_height,
                                    'max_viewport_width' => $template->max_viewport_width,
                                    'max_viewport_height' => $template->max_viewport_height];
                                if (DB::table('placement_sizes')->where($identity)->exists()) continue;
                                DB::table('placement_sizes')->insert($identity + ['id' => (string) Str::ulid(),
                                    'priority' => ++$priority, 'is_active' => true,
                                    'created_at' => now(), 'updated_at' => now()]);
                            }
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // Additive inventory: do not delete sizes that publishers may now use.
    }
};
