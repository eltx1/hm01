<?php

namespace App\Services\Inventory;

use App\Enums\PlacementDevice;
use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Models\AdUnit;
use App\Models\Placement;
use App\Models\PlacementTargeting;
use App\Models\Site;
use App\Models\SiteLayoutProfile;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SiteConfigPublisher $publisher,
    ) {}

    public function createAdUnit(Site $site, array $data, User $actor): AdUnit
    {
        return DB::transaction(function () use ($site, $data, $actor): AdUnit {
            $adUnit = AdUnit::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'name' => $data['name'],
                'code' => $this->code($data['code']),
                'description' => $data['description'] ?? null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->replaceAdUnitSizes($adUnit, $data['sizes'] ?? []);
            $this->touchConfiguration($site);
            $this->audit->record('inventory.ad_unit.created', $site->organization_id, $actor, $adUnit, newValues: [
                'site_id' => $site->id, 'code' => $adUnit->code, 'sizes' => $adUnit->sizes()->count(),
            ]);

            return $adUnit->load('sizes');
        });
    }

    public function createPlacement(Site $site, array $data, User $actor, bool $publish = true): Placement
    {
        return DB::transaction(function () use ($site, $data, $actor, $publish): Placement {
            $this->assertAdUnitBelongsToSite($site, $data['ad_unit_id'] ?? null);
            $placement = Placement::withoutGlobalScopes()->create($this->placementAttributes($site, $data, $actor));
            $this->replacePlacementSizes($placement, $data['sizes'] ?? []);
            $this->replaceTargeting($site, $placement, $data['targeting'] ?? []);
            $this->touchConfiguration($site);
            $this->audit->record('inventory.placement.created', $site->organization_id, $actor, $placement, newValues: [
                'site_id' => $site->id, 'code' => $placement->code, 'type' => $placement->type->value,
            ]);
            if ($publish) {
                $this->publisher->publishActiveProduction($site, $actor);
            }

            return $placement->load(['adUnit', 'sizes', 'targeting']);
        });
    }

    public function updatePlacement(Placement $placement, array $data, User $actor): Placement
    {
        $placement->loadMissing('site');
        return DB::transaction(function () use ($placement, $data, $actor): Placement {
            $site = $placement->site;
            $this->assertAdUnitBelongsToSite($site, $data['ad_unit_id'] ?? $placement->ad_unit_id);
            $before = $placement->toArray();
            $placement->update(array_merge($this->placementAttributes($site, $data, $actor, $placement), ['updated_by' => $actor->id]));
            if (array_key_exists('sizes', $data)) {
                $this->replacePlacementSizes($placement, $data['sizes']);
            }
            if (array_key_exists('targeting', $data)) {
                $this->replaceTargeting($site, $placement, $data['targeting']);
            }
            $this->touchConfiguration($site);
            $this->audit->record('inventory.placement.updated', $site->organization_id, $actor, $placement, $before, $placement->fresh()->toArray());
            $this->publisher->publishActiveProduction($site, $actor);

            return $placement->refresh()->load(['adUnit', 'sizes', 'targeting']);
        });
    }

    public function setPageTargeting(Site $site, array $targeting, User $actor): void
    {
        DB::transaction(function () use ($site, $targeting, $actor): void {
            PlacementTargeting::withoutGlobalScopes()->where('site_id', $site->id)->whereNull('placement_id')->delete();
            foreach ($targeting as $key => $values) {
                PlacementTargeting::withoutGlobalScopes()->create([
                    'organization_id' => $site->organization_id,
                    'site_id' => $site->id,
                    'placement_id' => null,
                    'scope' => 'PAGE',
                    'targeting_key' => $this->targetingKey($key),
                    'targeting_values' => $this->values($values),
                ]);
            }
            $this->touchConfiguration($site);
            $this->audit->record('inventory.page_targeting.updated', $site->organization_id, $actor, $site, newValues: ['keys' => array_keys($targeting)]);
            $this->publisher->publishActiveProduction($site, $actor);
        });
    }

    public function bulkCreatePlacements(Site $site, array $rows, User $actor): array
    {
        return DB::transaction(function () use ($site, $rows, $actor): array {
            $created = [];
            foreach ($rows as $row) {
                $created[] = $this->createPlacement($site, $row, $actor, false);
            }
            $this->publisher->publishActiveProduction($site, $actor);

            return $created;
        });
    }

    public function duplicateLayout(Site $source, Site $target, User $actor): SiteLayoutProfile
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target_site_id' => 'Choose a different target website.']);
        }

        $source->loadMissing(['adUnits.sizes', 'placements.sizes', 'placements.targeting']);

        return DB::transaction(function () use ($source, $target, $actor): SiteLayoutProfile {
            $adUnitMap = [];
            foreach ($source->adUnits as $sourceAdUnit) {
                $targetAdUnit = AdUnit::withoutGlobalScopes()->firstOrCreate(
                    ['site_id' => $target->id, 'code' => $sourceAdUnit->code],
                    [
                        'organization_id' => $target->organization_id,
                        'name' => $sourceAdUnit->name,
                        'description' => $sourceAdUnit->description,
                        'is_enabled' => $sourceAdUnit->is_enabled,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ],
                );
                $this->replaceAdUnitSizes($targetAdUnit, $sourceAdUnit->sizes->map(fn ($size) => $size->only(['size_type', 'width', 'height', 'label', 'is_active']))->all());
                $adUnitMap[$sourceAdUnit->id] = $targetAdUnit->id;
            }

            foreach ($source->placements as $sourcePlacement) {
                $targetPlacement = Placement::withoutGlobalScopes()->updateOrCreate(
                    ['site_id' => $target->id, 'code' => $sourcePlacement->code],
                    [
                        'organization_id' => $target->organization_id,
                        'ad_unit_id' => $sourcePlacement->ad_unit_id ? ($adUnitMap[$sourcePlacement->ad_unit_id] ?? null) : null,
                        'ad_format_id' => $sourcePlacement->ad_format_id,
                        'name' => $sourcePlacement->name,
                        'type' => $sourcePlacement->type,
                        'status' => $sourcePlacement->status,
                        'lazy_load_enabled' => $sourcePlacement->lazy_load_enabled,
                        'lazy_fetch_margin_percent' => $sourcePlacement->lazy_fetch_margin_percent,
                        'lazy_render_margin_percent' => $sourcePlacement->lazy_render_margin_percent,
                        'lazy_mobile_scaling' => $sourcePlacement->lazy_mobile_scaling,
                        'refresh_enabled' => $sourcePlacement->refresh_enabled,
                        'refresh_interval_seconds' => $sourcePlacement->refresh_interval_seconds,
                        'refresh_limit' => $sourcePlacement->refresh_limit,
                        'collapse_empty_div' => $sourcePlacement->collapse_empty_div,
                        'safeframe_enabled' => $sourcePlacement->safeframe_enabled,
                        'sort_order' => $sourcePlacement->sort_order,
                        'metadata' => $sourcePlacement->metadata,
                        'format_settings' => $sourcePlacement->format_settings,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ],
                );
                $this->replacePlacementSizes($targetPlacement, $sourcePlacement->sizes->map(fn ($size) => $size->only([
                    'size_type', 'width', 'height', 'device', 'min_viewport_width', 'min_viewport_height',
                    'max_viewport_width', 'max_viewport_height', 'priority', 'is_active',
                ]))->all());
                $this->replaceTargeting($target, $targetPlacement, $sourcePlacement->targeting->mapWithKeys(fn ($item) => [$item->targeting_key => $item->targeting_values])->all());
            }

            $snapshot = [
                'sourceSiteId' => $source->id,
                'sourceSiteKey' => $source->public_key,
                'adUnits' => $source->adUnits->count(),
                'placements' => $source->placements->count(),
                'createdAt' => now()->utc()->toIso8601String(),
            ];
            $profile = SiteLayoutProfile::withoutGlobalScopes()->create([
                'organization_id' => $target->organization_id,
                'site_id' => $target->id,
                'source_site_id' => $source->id,
                'name' => 'Copy of '.$source->display_name,
                'description' => 'Layout duplicated from '.$source->primary_domain,
                'snapshot' => $snapshot,
                'created_by' => $actor->id,
            ]);
            $this->touchConfiguration($target);
            $this->audit->record('inventory.layout.duplicated', $target->organization_id, $actor, $profile, newValues: $snapshot);
            $this->publisher->publishActiveProduction($target, $actor);

            return $profile;
        });
    }

    private function placementAttributes(Site $site, array $data, User $actor, ?Placement $existing = null): array
    {
        return [
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'ad_unit_id' => $data['ad_unit_id'] ?? $existing?->ad_unit_id,
            'ad_format_id' => $data['ad_format_id'] ?? $existing?->ad_format_id,
            'name' => $data['name'] ?? $existing?->name,
            'code' => $this->code($data['code'] ?? $existing?->code),
            'type' => isset($data['type']) ? PlacementType::from($data['type']) : $existing?->type ?? PlacementType::Display,
            'status' => isset($data['status']) ? PlacementStatus::from($data['status']) : $existing?->status ?? PlacementStatus::Active,
            'lazy_load_enabled' => (bool) ($data['lazy_load_enabled'] ?? $existing?->lazy_load_enabled ?? true),
            'lazy_fetch_margin_percent' => (int) ($data['lazy_fetch_margin_percent'] ?? $existing?->lazy_fetch_margin_percent ?? 500),
            'lazy_render_margin_percent' => (int) ($data['lazy_render_margin_percent'] ?? $existing?->lazy_render_margin_percent ?? 200),
            'lazy_mobile_scaling' => (float) ($data['lazy_mobile_scaling'] ?? $existing?->lazy_mobile_scaling ?? 2),
            'refresh_enabled' => (bool) ($data['refresh_enabled'] ?? $existing?->refresh_enabled ?? false),
            'refresh_interval_seconds' => $data['refresh_interval_seconds'] ?? $existing?->refresh_interval_seconds,
            'refresh_limit' => $data['refresh_limit'] ?? $existing?->refresh_limit,
            'collapse_empty_div' => (bool) ($data['collapse_empty_div'] ?? $existing?->collapse_empty_div ?? true),
            'safeframe_enabled' => (bool) ($data['safeframe_enabled'] ?? $existing?->safeframe_enabled ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? $existing?->sort_order ?? 0),
            'metadata' => $data['metadata'] ?? $existing?->metadata,
            'format_settings' => $data['format_settings'] ?? $existing?->format_settings,
            'created_by' => $existing?->created_by ?? $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function replaceAdUnitSizes(AdUnit $adUnit, array $sizes): void
    {
        $adUnit->sizes()->delete();
        foreach ($sizes as $size) {
            $adUnit->sizes()->create([
                'size_type' => strtoupper($size['size_type'] ?? 'FIXED'),
                'width' => $size['width'] ?? null,
                'height' => $size['height'] ?? null,
                'label' => $size['label'] ?? null,
                'is_active' => (bool) ($size['is_active'] ?? true),
            ]);
        }
    }

    private function replacePlacementSizes(Placement $placement, array $sizes): void
    {
        $placement->sizes()->delete();
        foreach ($sizes as $index => $size) {
            $device = $size['device'] ?? PlacementDevice::All->value;
            $placement->sizes()->create([
                'size_type' => strtoupper($size['size_type'] ?? 'FIXED'),
                'width' => $size['width'] ?? null,
                'height' => $size['height'] ?? null,
                'device' => $device instanceof PlacementDevice ? $device : PlacementDevice::from($device),
                'min_viewport_width' => (int) ($size['min_viewport_width'] ?? 0),
                'min_viewport_height' => (int) ($size['min_viewport_height'] ?? 0),
                'max_viewport_width' => $size['max_viewport_width'] ?? null,
                'max_viewport_height' => $size['max_viewport_height'] ?? null,
                'priority' => (int) ($size['priority'] ?? $index),
                'is_active' => (bool) ($size['is_active'] ?? true),
            ]);
        }
    }

    private function replaceTargeting(Site $site, Placement $placement, array $targeting): void
    {
        PlacementTargeting::withoutGlobalScopes()->where('placement_id', $placement->id)->delete();
        foreach ($targeting as $key => $values) {
            PlacementTargeting::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'placement_id' => $placement->id,
                'scope' => 'PLACEMENT',
                'targeting_key' => $this->targetingKey($key),
                'targeting_values' => $this->values($values),
            ]);
        }
    }

    private function touchConfiguration(Site $site): void
    {
        $site->servingSettings()->increment('configuration_version');
    }

    private function assertAdUnitBelongsToSite(Site $site, ?string $adUnitId): void
    {
        if (! $adUnitId) {
            return;
        }

        if (! AdUnit::withoutGlobalScopes()->whereKey($adUnitId)->where('site_id', $site->id)->exists()) {
            throw ValidationException::withMessages(['ad_unit_id' => 'The selected ad unit does not belong to this website.']);
        }
    }

    private function code(?string $code): string
    {
        $code = strtolower(trim((string) $code));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,119}$/', $code)) {
            throw ValidationException::withMessages(['code' => 'Codes must be 2-120 lowercase letters, numbers, underscores, or dashes.']);
        }

        return $code;
    }

    private function targetingKey(string $key): string
    {
        $key = trim($key);
        if (! preg_match('/^[A-Za-z0-9_]{1,120}$/', $key)) {
            throw ValidationException::withMessages(['targeting' => 'Targeting keys may contain only letters, numbers, and underscores.']);
        }

        return $key;
    }

    private function values(mixed $values): array
    {
        $items = is_string($values) ? explode(',', $values) : (array) $values;

        return array_values(array_filter(array_map(fn ($value) => trim((string) $value), $items), fn ($value) => $value !== ''));
    }
}
