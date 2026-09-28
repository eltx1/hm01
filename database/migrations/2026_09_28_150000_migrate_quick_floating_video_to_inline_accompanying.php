<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateFloatingVideoSettings(true);
    }

    public function down(): void
    {
        $this->updateFloatingVideoSettings(false);
    }

    private function updateFloatingVideoSettings(bool $inlineFirst): void
    {
        DB::table('placements')
            ->select(['id', 'metadata', 'format_settings'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($inlineFirst): void {
                foreach ($rows as $row) {
                    $metadata = $this->decodeJson($row->metadata);
                    if (! (bool) ($metadata['quick_monetize_generated'] ?? false)
                        || (string) ($metadata['placement_preset'] ?? '') !== 'video_floating') {
                        continue;
                    }

                    $settings = $this->decodeJson($row->format_settings);
                    if ($inlineFirst) {
                        $settings['autoMount'] = false;
                        unset($settings['autoMountTarget']);
                        $settings['reserveSpace'] = true;
                        $settings['responsive'] = true;
                        $settings['position'] = 'inline_to_bottom_right';
                        $settings['floatingPosition'] = 'bottom_right';
                        $settings['closeable'] = true;
                        $settings['closeOutside'] = true;
                        $settings['singleActiveVideo'] = true;
                        $settings['minimumVisibleRatio'] = 0.5;
                        $settings['mutedAutoplay'] = true;
                    } else {
                        $settings['autoMount'] = true;
                        $settings['autoMountTarget'] = 'body_end';
                        $settings['reserveSpace'] = false;
                        $settings['responsive'] = true;
                        $settings['position'] = 'bottom_right';
                        unset($settings['floatingPosition']);
                        $settings['closeable'] = true;
                        $settings['closeOutside'] = true;
                        $settings['singleActiveVideo'] = true;
                        $settings['minimumVisibleRatio'] = 0.5;
                        $settings['mutedAutoplay'] = true;
                    }

                    DB::table('placements')->where('id', $row->id)->update([
                        'format_settings' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
