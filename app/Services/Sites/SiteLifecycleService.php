<?php

namespace App\Services\Sites;

use App\Enums\AccountStatus;
use App\Enums\ConfigEnvironment;
use App\Enums\PublisherApplicationStatus;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\LoaderRelease;
use App\Models\ServingModeChange;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\SiteDomain;
use App\Models\SiteServingSetting;
use App\Models\SiteStatusHistory;
use App\Models\TagVersion;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Notifications\DomainNotificationService;
use App\Services\SupplyChain\HorusSellerIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteLifecycleService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SiteConfigPublisher $publisher,
        private readonly DomainNotificationService $notifications,
        private readonly HorusSellerIdentityService $sellerIdentities,
    ) {}

    public function create(array $data, User $actor): Site
    {
        return DB::transaction(function () use ($data, $actor): Site {
            $data['serving_mode'] = ServingMode::HorusGam;
            $data['status'] = SiteStatus::Draft;
            $site = Site::create($data);
            SiteDomain::create([
                'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'domain' => $site->primary_domain, 'is_primary' => true,
                'verification_token' => Str::random(48),
            ]);
            SiteServingSetting::create([
                'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'serving_mode' => ServingMode::HorusGam,
                'revenue_share_percent' => $site->default_revenue_share_percent,
                'prebid_enabled' => $site->prebid_enabled,
                'native_demand_enabled' => $site->native_demand_enabled,
            ]);
            SiteConfig::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'loader_release_id' => LoaderRelease::query()->where('is_active', true)->latest('published_at')->value('id'),
                'tag_version_id' => TagVersion::query()->where('is_active', true)->latest('published_at')->value('id'),
                'status' => 'ACTIVE',
                'cache_ttl_seconds' => config('horus.config_cache_ttl_seconds', 60),
            ]);
            SiteStatusHistory::create([
                'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'new_status' => SiteStatus::Draft, 'changed_by' => $actor->id,
                'reason' => 'Website created.',
            ]);

            // Legacy publishers that already participate in the managed identity
            // lifecycle keep the original behavior. Active express registrations
            // receive HMP/HMS identities when their first site supplies the
            // canonical domain required by sellers.json.
            $publisher = $site->publisher()->withoutGlobalScopes()->firstOrFail();
            $approvedApplication = $publisher->application()->withoutGlobalScopes()
                ->where('status', PublisherApplicationStatus::Approved->value)
                ->exists();
            if ($this->sellerIdentities->managedForPublisher($publisher)
                || ($publisher->status === AccountStatus::Active && $approvedApplication)) {
                if (blank($publisher->business_domain)) {
                    $publisher->update(['business_domain' => $site->primary_domain]);
                }
                $this->sellerIdentities->ensureForSite($site, $actor);
            }

            $this->audit->record('site.created', $site->organization_id, $actor, $site, newValues: ['serving_mode' => ServingMode::HorusGam->value, 'status' => SiteStatus::Draft->value, 'primary_domain' => $site->primary_domain]);

            return $site;
        });
    }

    public function transition(Site $site, SiteStatus $newStatus, User $actor, ?string $reason = null, bool $notify = true): Site
    {
        $oldStatus = $site->status;
        if ($oldStatus === $newStatus) {
            return $site;
        }

        $allowed = [
            SiteStatus::Draft->value => [SiteStatus::PendingVerification, SiteStatus::PendingReview, SiteStatus::Suspended],
            SiteStatus::PendingVerification->value => [SiteStatus::PendingReview, SiteStatus::Suspended],
            SiteStatus::PendingReview->value => [SiteStatus::Approved, SiteStatus::Rejected, SiteStatus::Suspended],
            SiteStatus::Approved->value => [SiteStatus::Active, SiteStatus::Suspended, SiteStatus::Archived],
            SiteStatus::Active->value => [SiteStatus::Suspended, SiteStatus::Archived],
            SiteStatus::Rejected->value => [SiteStatus::Draft, SiteStatus::PendingReview, SiteStatus::Archived],
            SiteStatus::Suspended->value => [SiteStatus::Draft, SiteStatus::PendingVerification, SiteStatus::PendingReview, SiteStatus::Approved, SiteStatus::Active, SiteStatus::Rejected, SiteStatus::Archived],
            SiteStatus::Archived->value => [],
        ];

        if (! in_array($newStatus, $allowed[$oldStatus->value], true)) {
            throw ValidationException::withMessages(['status' => "Invalid website transition from {$oldStatus->value} to {$newStatus->value}."]);
        }

        DB::transaction(function () use ($site, $oldStatus, $newStatus, $actor, $reason): void {
            $site->update([
                'status' => $newStatus,
                'submitted_at' => $newStatus === SiteStatus::PendingReview ? now() : $site->submitted_at,
                'approved_at' => $newStatus === SiteStatus::Approved ? now() : $site->approved_at,
            ]);
            SiteStatusHistory::create([
                'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'previous_status' => $oldStatus, 'new_status' => $newStatus,
                'changed_by' => $actor->id, 'reason' => $reason,
            ]);
            $this->audit->record('site.status.changed', $site->organization_id, $actor, $site, ['status' => $oldStatus->value], ['status' => $newStatus->value], ['reason' => $reason]);

            if ($newStatus === SiteStatus::Active) {
                $this->publisher->publishActiveProduction($site, $actor);
            } elseif ($oldStatus === SiteStatus::Active && in_array($newStatus, [SiteStatus::Suspended, SiteStatus::Archived], true)) {
                $this->publisher->publishUrgent($site, ConfigEnvironment::Production, $actor);
            }
        });

        if ($notify) {
            $this->notifications->siteStatusChanged($site->refresh(), $oldStatus);
        }

        return $site->refresh();
    }

    public function changeServingMode(Site $site, ServingMode $mode, User $administrator, string $reason, ?ServingModeChange $rollbackReference = null): Site
    {
        $previous = $site->serving_mode;
        if ($previous === $mode) {
            return $site;
        }

        DB::transaction(function () use ($site, $mode, $administrator, $reason, $rollbackReference, $previous): void {
            $site->update(['serving_mode' => $mode]);
            $settings = $site->servingSettings()->firstOrFail();
            $settings->update(['serving_mode' => $mode, 'configuration_version' => $settings->configuration_version + 1]);
            ServingModeChange::create([
                'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'previous_mode' => $previous, 'new_mode' => $mode,
                'administrator_id' => $administrator->id, 'reason' => $reason,
                'rollback_reference_id' => $rollbackReference?->id,
            ]);
            $this->audit->record('site.serving_mode.changed', $site->organization_id, $administrator, $site, ['serving_mode' => $previous->value], ['serving_mode' => $mode->value], ['reason' => $reason, 'rollback_reference_id' => $rollbackReference?->id]);
        });

        $this->notifications->siteServingChanged($site->refresh(), $previous->value, $mode->value);

        return $site->refresh();
    }

    public function changeRevenueShare(Site $site, string $percentage, User $administrator, string $reason): Site
    {
        $previous = $site->default_revenue_share_percent;
        DB::transaction(function () use ($site, $percentage, $administrator, $reason, $previous): void {
            $site->update(['default_revenue_share_percent' => $percentage]);
            $settings = $site->servingSettings()->firstOrFail();
            $settings->update(['revenue_share_percent' => $percentage, 'configuration_version' => $settings->configuration_version + 1]);
            $this->audit->record('site.revenue_share.changed', $site->organization_id, $administrator, $site, ['revenue_share_percent' => $previous], ['revenue_share_percent' => $percentage], ['reason' => $reason]);
        });

        return $site->refresh();
    }

    public function emergencyPause(Site $site, User $administrator, string $reason): Site
    {
        DB::transaction(function () use ($site, $administrator, $reason): void {
            $wasActive = $site->status === SiteStatus::Active;
            $this->changeServingMode($site, ServingMode::Paused, $administrator, $reason);
            SiteConfig::withoutGlobalScopes()->updateOrCreate(
                ['site_id' => $site->id],
                ['organization_id' => $site->organization_id, 'immediate_pause' => true, 'status' => 'PAUSED'],
            );
            $site->refresh();
            if ($site->status !== SiteStatus::Suspended && $site->status !== SiteStatus::Archived) {
                $this->transition($site, SiteStatus::Suspended, $administrator, 'Emergency pause: '.$reason);
            }
            if (! $wasActive) {
                $this->publisher->publishUrgent($site, ConfigEnvironment::Production, $administrator);
            }
        });

        return $site->refresh();
    }
}
