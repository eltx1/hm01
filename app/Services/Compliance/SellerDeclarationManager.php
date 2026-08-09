<?php

namespace App\Services\Compliance;

use App\Enums\ConfigEnvironment;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SiteStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SellerDeclarationManager
{
    public function __construct(
        private readonly SupplyChainInvariantService $invariants,
        private readonly SiteConfigPublisher $publisher,
    ) {}

    public function create(array $attributes, User $actor): SellerDeclaration
    {
        return DB::transaction(function () use ($attributes, $actor): SellerDeclaration {
            $publisher = Publisher::withoutGlobalScope('organization')->lockForUpdate()->findOrFail($attributes['publisher_id']);
            $site = $this->site($attributes['site_id'] ?? null);

            return $this->invariants->createSellerDeclaration($publisher, $site, $attributes, $actor);
        });
    }

    public function update(SellerDeclaration $declaration, array $attributes, User $actor): SellerDeclaration
    {
        return DB::transaction(function () use ($declaration, $attributes, $actor): SellerDeclaration {
            $beforeSiteIds = $this->affectedSiteIds($declaration);
            $updated = $this->invariants->updateSellerDeclaration(
                $declaration,
                $this->site($attributes['site_id'] ?? null),
                $attributes,
                $actor,
            );
            $this->queueStaticChanges($beforeSiteIds->merge($this->affectedSiteIds($updated))->unique(), $actor);

            return $updated;
        });
    }

    public function review(
        SellerDeclaration $declaration,
        SupplyChainReviewStatus $status,
        User $actor,
    ): SellerDeclaration {
        return DB::transaction(function () use ($declaration, $status, $actor): SellerDeclaration {
            $locked = SellerDeclaration::withoutGlobalScope('organization')->lockForUpdate()->findOrFail($declaration->id);
            $wasActive = $this->status($locked) === SellerDeclarationStatus::Active;
            $reviewed = $this->invariants->reviewSellerDeclaration($locked, $status, $actor);
            if ($wasActive && $this->status($reviewed) === SellerDeclarationStatus::Disabled) {
                $this->queueStaticChanges($this->affectedSiteIds($reviewed), $actor);
            }

            return $reviewed;
        });
    }

    public function activate(SellerDeclaration $declaration, User $actor): SellerDeclaration
    {
        return $this->transition($declaration, SellerDeclarationStatus::Active, $actor);
    }

    public function deactivate(SellerDeclaration $declaration, User $actor): SellerDeclaration
    {
        return $this->transition($declaration, SellerDeclarationStatus::Disabled, $actor);
    }

    private function transition(
        SellerDeclaration $declaration,
        SellerDeclarationStatus $status,
        User $actor,
    ): SellerDeclaration {
        return DB::transaction(function () use ($declaration, $status, $actor): SellerDeclaration {
            $locked = SellerDeclaration::withoutGlobalScope('organization')->lockForUpdate()->findOrFail($declaration->id);
            $siteIds = $this->affectedSiteIds($locked);
            if ($status === SellerDeclarationStatus::Active && $siteIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'A publisher must have at least one website before its seller declaration can be activated and published.',
                ]);
            }
            $updated = $this->invariants->changeSellerStatus($locked, $status, $actor);
            $this->queueStaticChanges($siteIds, $actor);

            return $updated;
        });
    }

    private function site(mixed $siteId): ?Site
    {
        return filled($siteId) ? Site::withoutGlobalScope('organization')->findOrFail((string) $siteId) : null;
    }

    /** @return Collection<int, string> */
    private function affectedSiteIds(SellerDeclaration $declaration): Collection
    {
        if ($declaration->site_id) {
            return collect([(string) $declaration->site_id]);
        }

        return Site::withoutGlobalScope('organization')
            ->where('publisher_id', $declaration->publisher_id)->orderBy('id')->pluck('id');
    }

    /** @param Collection<int, string> $siteIds */
    private function queueStaticChanges(Collection $siteIds, User $actor): void
    {
        $sites = Site::withoutGlobalScope('organization')->whereIn('id', $siteIds)->orderBy('id')->get();
        $queued = false;
        foreach ($sites->where('status', SiteStatus::Active) as $site) {
            $this->publisher->publishActiveProduction($site, $actor);
            $queued = true;
        }
        if (! $queued && $sites->isNotEmpty()) {
            // A paused payload is safe and gives a seller-only change a durable
            // static-delivery outbox trigger even before this publisher goes live.
            $this->publisher->publish($sites->first(), ConfigEnvironment::Production, $actor);
        }
    }

    private function status(SellerDeclaration $declaration): SellerDeclarationStatus
    {
        return $declaration->status instanceof SellerDeclarationStatus
            ? $declaration->status
            : SellerDeclarationStatus::from((string) $declaration->status);
    }
}
