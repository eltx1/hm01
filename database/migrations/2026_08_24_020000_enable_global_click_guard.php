<?php

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\RuntimePolicyResolver;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production') || app()->runningUnitTests()) {
            return;
        }

        $actor = User::withoutGlobalScopes()
            ->whereHas('organization', fn ($query) => $query->where('type', OrganizationType::HorusMedia->value))
            ->whereHas('roles', fn ($query) => $query->where('name', RoleName::SuperAdmin->value))
            ->oldest('created_at')
            ->first();
        if (! $actor) {
            return;
        }

        $published = 0;
        $publisher = app(SiteConfigPublisher::class);
        Site::withoutGlobalScopes()
            ->where('status', SiteStatus::Active->value)
            ->orderBy('id')
            ->each(function (Site $site) use ($actor, $publisher, &$published): void {
                if ($publisher->publishActiveProduction($site, $actor, StaticDeliveryPriority::Normal)) {
                    $published++;
                }
            });

        $policy = app(RuntimePolicyResolver::class)->globalClickGuard();
        app(AuditRecorder::class)->record(
            'click_guard.global_default.deployed',
            null,
            $actor,
            null,
            [],
            $policy,
            ['active_site_configs_queued' => $published, 'client_only' => true],
        );
    }

    public function down(): void
    {
        // Published static configurations are append-only operational work.
    }
};
