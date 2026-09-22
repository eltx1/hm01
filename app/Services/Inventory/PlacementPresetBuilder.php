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
    public const RESPONSIVE_BUNDLE_SIZE = 6;
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
                return $this->reconcileQuickPreset($existing, $preset, $actor, is_array($choice) ? $choice : []);
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

    /** @return array<int, Placement> */
    public function responsiveBundle(Site $site, User $actor, ?string $name = null): array
    {
        return DB::transaction(function () use ($site, $actor, $name): array {
            Site::withoutGlobalScopes()->whereKey($site->id)->where('organization_id', $site->organization_id)->lockForUpdate()->firstOrFail();
            $first = $this->create($site, 'responsive_display', $actor, ['name' => $name], false, true);
            $members = [];
            for ($index = 1; $index <= self::RESPONSIVE_BUNDLE_SIZE; $index++) {
                $placement = $first;
                if ($index > 1) {
                    $matches = Placement::withoutGlobalScopes()
                        ->where('site_id', $site->id)->where('organization_id', $site->organization_id)
                        ->whereNull('deleted_at')->where('metadata->responsive_bundle', 'v1')
                        ->where('metadata->responsive_bundle_index', $index)->get();
                    if ($matches->count() > 1) throw ValidationException::withMessages(['placement_preset' => 'Duplicate responsive bundle members require inventory repair.']);
                    $placement = $matches->first();
                }
                $data = $this->presets->apply('responsive_display', [
                    'name' => trim((string) $name) !== ''
                        ? Str::limit(trim($name), 251, '').' · '.$index
                        : ($placement && data_get($placement->metadata, 'responsive_bundle') === 'v1'
                            ? $placement->name : 'Quick · Responsive Display · '.$index),
                    'code' => $placement?->code ?? $this->uniqueCode($site, null, 'quick_responsive_display_'.$index),
                    'ad_unit_id' => $placement?->ad_unit_id,
                    'metadata' => array_replace((array) ($placement?->metadata ?? []), [
                        'quick_monetize_generated' => true, 'placement_preset' => 'responsive_display',
                        'responsive_bundle' => 'v1', 'responsive_bundle_index' => $index,
                    ]),
                ]);
                $data['format_settings']['autoMount'] = false;
                $data['format_settings']['contentAlignment'] = 'center';
                $members[] = $placement
                    ? $this->inventory->updatePlacement($placement, $data, $actor, false)
                    : $this->inventory->createPlacement($site, $data, $actor, false);
            }
            return $members;
        });
    }

    private function existingQuickPreset(Site $site, string $preset): ?Placement
    {
        $matches = Placement::withoutGlobalScopes()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Placement $placement): bool => (bool) data_get($placement->metadata, 'quick_monetize_generated', false)
                && (string) data_get($placement->metadata, 'placement_preset', '') === $preset
                && (int) data_get($placement->metadata, 'responsive_bundle_index', 1) === 1)
            ->values();

        $active = $matches
            ->filter(fn (Placement $placement): bool => ! $placement->trashed()
                && $placement->status === PlacementStatus::Active)
            ->values();

        if ($active->count() > 1) {
            throw ValidationException::withMessages([
                'placement_preset' => "Multiple active Quick Monetize surfaces already exist for [{$preset}]. Refusing to choose between overlapping inventory; reconcile the duplicates first.",
            ]);
        }

        /** @var Placement|null $existing */
        $existing = $active->first();
        if ($existing) {
            // Historical disabled/deleted duplicates may remain for audit and
            // rollback after a repair. They must not block the one canonical
            // active surface from being safely reused on future activations.
            return $existing;
        }

        // An explicit Quick Monetize activation is also an explicit request to
        // bring a previously-disabled generated surface back into service.
        // Prefer the stable canonical code when repair history left multiple
        // disabled audit records behind, otherwise only auto-restore when the
        // choice is unambiguous. Soft-deleted inventory is never resurrected.
        $restorable = $matches
            ->filter(fn (Placement $placement): bool => ! $placement->trashed()
                && $placement->status === PlacementStatus::Disabled)
            ->values();

        $canonical = $restorable
            ->filter(fn (Placement $placement): bool => $placement->code === 'quick_'.$preset)
            ->values();

        if ($canonical->count() === 1) {
            return $canonical->first();
        }
        if ($canonical->count() > 1 || $restorable->count() > 1) {
            throw ValidationException::withMessages([
                'placement_preset' => "Multiple disabled Quick Monetize surfaces exist for [{$preset}]. Refusing to choose a surface automatically; reconcile the duplicates first.",
            ]);
        }
        if ($restorable->count() === 1) {
            return $restorable->first();
        }

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages([
                'placement_preset' => "A Quick Monetize surface for [{$preset}] already exists but no safe canonical surface is available for activation. Repair or explicitly reuse that inventory instead of creating a duplicate.",
            ]);
        }

        return null;
    }

    /**
     * Reapply the maintained preset before reusing a generated Quick surface.
     * This both reactivates repair-disabled inventory and upgrades older
     * generated surfaces to the current size/responsive policy atomically.
     *
     * @param array<string, mixed> $choice
     */
    private function reconcileQuickPreset(Placement $placement, string $preset, User $actor, array $choice): Placement
    {
        $placement->loadMissing(['targeting', 'sizes']);
        $targeting = $placement->targeting
            ->mapWithKeys(fn ($record): array => [$record->targeting_key => (array) ($record->targeting_values ?? [])])
            ->all();

        $data = $this->presets->apply($preset, [
            'ad_unit_id' => $placement->ad_unit_id,
            'name' => $placement->name,
            'code' => $placement->code,
            'targeting' => $targeting,
            'lazy_fetch_margin_percent' => $placement->lazy_fetch_margin_percent,
            'lazy_render_margin_percent' => $placement->lazy_render_margin_percent,
            'lazy_mobile_scaling' => $placement->lazy_mobile_scaling,
            'refresh_interval_seconds' => $placement->refresh_interval_seconds,
            'refresh_limit' => $placement->refresh_limit,
            'sort_order' => $placement->sort_order,
            'metadata' => (array) ($placement->metadata ?? []),
        ]);

        $settings = (array) ($data['format_settings'] ?? []);
        $settings['autoMount'] = data_get($placement->metadata, 'responsive_bundle') !== 'v1';
        if (! $settings['autoMount']) $settings['contentAlignment'] = 'center';
        $settings['autoMountTarget'] = (string) ($choice['quickMount'] ?? $this->defaultQuickMountTarget($preset));
        $data['format_settings'] = $settings;

        $metadata = (array) ($data['metadata'] ?? []);
        $metadata['quick_monetize_generated'] = true;
        $metadata['placement_preset'] = $preset;
        $data['metadata'] = $metadata;
        $data['status'] = PlacementStatus::Active->value;

        // QuickMonetizeService owns the enclosing transaction and performs one
        // final production publish after Demand mappings/widgets are restored.
        // Avoid publishing a half-reconciled placement before that wiring exists.
        return $this->inventory->updatePlacement($placement, $data, $actor, false);
    }

    private function defaultQuickMountTarget(string $preset): string
    {
        return match ($preset) {
            'in_article_display', 'video_outstream' => 'content_mid',
            'video_floating', 'sticky_bottom', 'sticky_top', 'side_rail_right', 'side_rail_left' => 'body_end',
            'rewarded' => 'article_end',
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
