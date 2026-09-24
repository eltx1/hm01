<?php

namespace App\Services\Demand;

use App\Enums\ConfigEnvironment;
use App\Enums\PlacementStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\Placement;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuickAdManagementService
{
    public function __construct(
        private readonly InventoryManager $inventory,
        private readonly SiteConfigurationBuilder $builder,
        private readonly SiteConfigPublisher $publisher,
    ) {}

    public function placements(Site $site): Builder
    {
        return Placement::query()
            ->where('site_id', $site->id)
            ->where('organization_id', $site->organization_id)
            ->where(fn (Builder $query) => $query
                ->where('metadata->quick_monetize_generated', true)
                ->orWhereHas('demandPlacements', fn (Builder $mapping) => $mapping
                    ->where('organization_id', $site->organization_id)
                    ->where('configuration->quick_monetize_managed', true)));
    }

    /** Change one surface only; shared accounts, tags and reports remain intact. */
    public function change(Site $site, Placement $placement, string $action, User $actor): bool
    {
        $status = match ($action) {
            'pause', 'restore' => PlacementStatus::Paused,
            'resume' => PlacementStatus::Active,
            'remove' => PlacementStatus::Disabled,
            default => throw ValidationException::withMessages(['action' => 'Choose a valid ad action.']),
        };

        return DB::transaction(function () use ($site, $placement, $action, $status, $actor): bool {
            // Use the same site-first lock order as preset creation and publication.
            $site = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $placement = $this->placements($site)->whereKey($placement->id)->lockForUpdate()->firstOrFail();
            if ($placement->status === $status) {
                return false;
            }
            if ($action === 'resume' && $placement->status === PlacementStatus::Disabled) {
                throw ValidationException::withMessages(['action' => 'Restore this removed ad first, then resume it when ready.']);
            }
            if ($action === 'restore' && $placement->status !== PlacementStatus::Disabled) {
                throw ValidationException::withMessages(['action' => 'Only removed ads can be restored.']);
            }
            if ($action === 'pause' && $placement->status === PlacementStatus::Disabled) {
                throw ValidationException::withMessages(['action' => 'This ad is removed. Use Restore to return it to paused ads.']);
            }

            // InventoryManager preserves all omitted settings and records the audit.
            // DISABLED is a reversible removal, preserving placement IDs and history.
            $placement = $this->inventory->updatePlacement($placement, ['status' => $status->value], $actor, false);
            if ($status === PlacementStatus::Active) {
                $config = $this->builder->build($site->fresh(), ConfigEnvironment::Production, 0);
                $surface = collect($config['placements'] ?? [])->firstWhere('code', $placement->code);
                if (! $surface || ! ($surface['enabled'] ?? false)
                    || ($surface['renderer'] ?? null) !== 'DIRECT_JS'
                    || ($surface['rendererConflict'] ?? false)
                    || empty($config['directDemand']['placements'][$placement->code]['candidates'])) {
                    throw ValidationException::withMessages(['action' => 'This ad cannot resume while its website, provider or placement is unavailable or has a renderer conflict. Review its advanced settings; your other ads are unchanged.']);
                }
            }

            $this->publisher->publishActiveProduction($site->fresh(), $actor,
                in_array($action, ['pause', 'remove'], true) ? StaticDeliveryPriority::Urgent : StaticDeliveryPriority::Normal);

            return true;
        });
    }
}
