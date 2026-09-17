<?php

namespace App\Services\Inventory;

use App\Enums\PlacementStatus;
use App\Models\Placement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlacementPresetBuilder
{
    public function __construct(
        private readonly PlacementPresetCatalog $presets,
        private readonly InventoryManager $inventory,
    ) {}

    /**
     * Create one provider-agnostic inventory surface from a maintained preset.
     * Demand/provider wiring is deliberately outside this service.
     *
     * @param array<string, mixed> $overrides
     */
    public function create(
        Site $site,
        string $preset,
        User $actor,
        array $overrides = [],
        bool $publish = true,
        bool $quickMount = false,
    ): Placement {
        // A Quick surface has one stable identity per site+preset. Make that
        // guarantee hold for double-clicks/retries too, not only sequential
        // requests: enter a transaction when needed, lock the site row, then
        // perform the lookup/create while the lock is held.
        if ($quickMount && DB::transactionLevel() === 0) {
            return DB::transaction(fn (): Placement => $this->create(
                $site,
                $preset,
                $actor,
                $overrides,
                $publish,
                true,
            ));
        }

        $choice = $this->presets->choices()[$preset] ?? null;
        $label = is_array($choice) ? (string) ($choice['label'] ?? 'Placement') : 'Placement';

        // Quick Monetize is a one-click surface workflow, not a placement clone
        // button. Repeated activation of the same preset must update/reuse the
        // existing generated surface instead of silently creating overlapping
        // sticky anchors (or duplicate in-page inventory). Advanced inventory
        // remains available when an operator intentionally needs two surfaces
        // of the same type.
        if ($quickMount) {
            Site::withoutGlobalScopes()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $existing = $this->existingQuickPreset($site, $preset);
            if ($existing) {
                return $existing;
            }
        }

        $name = trim((string) ($overrides['name'] ?? ''));
        if ($name === '') {
            $name = $quickMount ? 'Quick · '.$label : $label;
        }

        $overrides['name'] = $name;
        $overrides['code'] = $this->uniqueCode(
            $site,
            isset($overrides['code']) && $overrides['code'] !== null ? (string) $overrides['code'] : null,
            $quickMount ? 'quick_'.$preset : $name,
        );

        $data = $this->presets->apply($preset, array_replace([
            'ad_unit_id' => $overrides['ad_unit_id'] ?? null,
            'targeting' => [],
            'lazy_fetch_margin_percent' => 500,
            'lazy_render_margin_percent' => 200,
            'lazy_mobile_scaling' => 2,
            'refresh_interval_seconds' => null,
            'refresh_limit' => null,
            'sort_order' => 0,
        ], $overrides));

        if ($quickMount) {
            $settings = (array) ($data['format_settings'] ?? []);
            $settings['autoMount'] = true;
            $settings['autoMountTarget'] = (string) ($choice['quickMount'] ?? $this->defaultQuickMountTarget($preset));
            $data['format_settings'] = $settings;

            $metadata = (array) ($data['metadata'] ?? []);
            $metadata['quick_monetize_generated'] = true;
            $metadata['placement_preset'] = $preset;
            $data['metadata'] = $metadata;
        }

        return $this->inventory->createPlacement($site, $data, $actor, $publish);
    }

    private function existingQuickPreset(Site $site, string $preset): ?Placement
    {
        $matches = Placement::withoutGlobalScopes()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Placement $placement): bool => (bool) data_get($placement->metadata, 'quick_monetize_generated', false)
                && (string) data_get($placement->metadata, 'placement_preset', '') === $preset)
            ->values();

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'placement_preset' => "Multiple Quick Monetize surfaces already exist for [{$preset}]. Refusing to create another duplicate; reconcile the existing inventory first.",
            ]);
        }

        /** @var Placement|null $existing */
        $existing = $matches->first();
        if (! $existing) {
            return null;
        }

        if ($existing->trashed() || $existing->status !== PlacementStatus::Active) {
            throw ValidationException::withMessages([
                'placement_preset' => "A Quick Monetize surface for [{$preset}] already exists but is deleted or inactive. Repair or explicitly reuse that inventory instead of creating a duplicate.",
            ]);
        }

        return $existing;
    }

    private function defaultQuickMountTarget(string $preset): string
    {
        return match ($preset) {
            'in_article_display', 'video_outstream' => 'article_mid',
            'video_floating', 'sticky_bottom', 'sticky_top', 'side_rail_right', 'side_rail_left' => 'body_end',
            default => 'article_end',
        };
    }

    private function uniqueCode(Site $site, ?string $requested, string $fallback): string
    {
        $base = Str::of($requested ?: $fallback)
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '_')
            ->trim('_-')
            ->limit(100, '')
            ->value();
        $base = $base !== '' ? $base : 'placement';
        if (strlen($base) < 2) {
            $base .= '_ad';
        }

        $candidate = $base;
        $suffix = 2;
        while (Placement::withoutGlobalScopes()->withTrashed()
            ->where('site_id', $site->id)
            ->where('code', $candidate)
            ->exists()) {
            $candidate = Str::limit($base, 110, '').'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
