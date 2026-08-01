<?php

namespace App\Services\Inventory;

use App\Enums\ConfigEnvironment;
use App\Enums\ConfigVersionStatus;
use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\StaticDeliveryItem;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\StaticDelivery\CanonicalJson;
use App\Services\StaticDelivery\PublicPayloadGuard;
use App\Services\StaticDelivery\StaticPathGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SiteConfigPublisher
{
    public function __construct(
        private readonly SiteConfigurationBuilder $builder,
        private readonly AuditRecorder $audit,
        private readonly CanonicalJson $canonicalJson,
        private readonly PublicPayloadGuard $payloadGuard,
        private readonly StaticPathGuard $pathGuard,
    ) {
    }

    public function preview(Site $site, ConfigEnvironment $environment): array
    {
        return $this->builder->build($site->refresh(), $environment, $this->nextVersion($site, $environment));
    }

    public function publish(Site $site, ConfigEnvironment $environment, User $actor): ConfigVersion
    {
        return DB::transaction(fn () => $this->queueVersion($site, $environment, $actor));
    }

    public function publishUrgent(Site $site, ConfigEnvironment $environment, User $actor): ConfigVersion
    {
        return DB::transaction(fn () => $this->queueVersion($site, $environment, $actor, StaticDeliveryPriority::Urgent));
    }

    public function rollback(Site $site, ConfigEnvironment $environment, ConfigVersion $target, User $actor): ConfigVersion
    {
        if ($target->site_id !== $site->id || $target->environment !== $environment) {
            throw new RuntimeException('The selected configuration version does not belong to this site and environment.');
        }

        return DB::transaction(function () use ($site, $environment, $target, $actor): ConfigVersion {
            $version = $this->nextVersion($site, $environment);
            $payload = $target->payload;
            $payload['configVersion'] = $version;
            $payload['generatedAt'] = now()->utc()->toIso8601String();
            $payload['rollbackSourceVersion'] = $target->version;

            return $this->storePendingVersion($site, $environment, $version, $payload, $actor, StaticDeliveryPriority::Normal, $target);
        });
    }

    public function pauseImmediately(Site $site, User $actor): ConfigVersion
    {
        return DB::transaction(function () use ($site, $actor): ConfigVersion {
            $this->ensureSiteConfig($site)->update(['immediate_pause' => true, 'status' => 'PAUSED']);

            return $this->queueVersion($site, ConfigEnvironment::Production, $actor, StaticDeliveryPriority::Urgent);
        });
    }

    public function resume(Site $site, User $actor): ConfigVersion
    {
        return DB::transaction(function () use ($site, $actor): ConfigVersion {
            $this->ensureSiteConfig($site)->update(['immediate_pause' => false, 'status' => 'ACTIVE']);

            return $this->queueVersion($site, ConfigEnvironment::Production, $actor);
        });
    }

    private function queueVersion(
        Site $site,
        ConfigEnvironment $environment,
        User $actor,
        StaticDeliveryPriority $priority = StaticDeliveryPriority::Normal,
    ): ConfigVersion {
        $version = $this->nextVersion($site, $environment);
        $payload = $this->builder->build($site->refresh(), $environment, $version);

        return $this->storePendingVersion($site, $environment, $version, $payload, $actor, $priority);
    }

    private function storePendingVersion(
        Site $site,
        ConfigEnvironment $environment,
        int $version,
        array $payload,
        User $actor,
        StaticDeliveryPriority $priority,
        ?ConfigVersion $source = null,
    ): ConfigVersion {
        $siteConfig = $this->ensureSiteConfig($site);
        $this->payloadGuard->validate($payload);
        $encoded = $this->canonicalJson->encode($payload);
        $checksum = hash('sha256', $encoded);
        $siteKey = $this->pathGuard->siteKey($site->public_key);
        $environmentName = strtolower($environment->value);
        $path = "configs/{$siteKey}/{$environmentName}.v{$version}.".substr($checksum, 0, 16).'.json';

        $record = ConfigVersion::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'site_config_id' => $siteConfig->id,
            'source_version_id' => $source?->id,
            'environment' => $environment,
            'version' => $version,
            'status' => ConfigVersionStatus::PendingDelivery,
            'payload' => $payload,
            'checksum' => $checksum,
            'file_path' => $path,
            'created_by' => $actor->id,
        ]);
        $delay = $priority === StaticDeliveryPriority::Urgent ? 0 : max(0, (int) config('static-delivery.batch_delay_seconds', 300));
        StaticDeliveryItem::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'config_version_id' => $record->id,
            'environment' => $environment,
            'status' => StaticDeliveryStatus::Pending,
            'priority' => $priority,
            'checksum' => $checksum,
            'available_at' => now()->addSeconds($delay),
            'created_by' => $actor->id,
        ]);

        $event = $source ? 'site.config.rollback.queued' : ($priority === StaticDeliveryPriority::Urgent ? 'site.config.urgent_delivery.queued' : 'site.config.delivery.queued');
        $this->audit->record($event, $site->organization_id, $actor, $record, newValues: [
            'site_id' => $site->id,
            'environment' => $environment->value,
            'version' => $version,
            'checksum' => $checksum,
            'file_path' => $path,
            'delivery_status' => StaticDeliveryStatus::Pending->value,
            'priority' => $priority->value,
            'rollback_source_version' => $source?->version,
        ]);

        return $record;
    }

    private function ensureSiteConfig(Site $site): SiteConfig
    {
        return SiteConfig::withoutGlobalScopes()->firstOrCreate(
            ['site_id' => $site->id],
            ['organization_id' => $site->organization_id, 'cache_ttl_seconds' => config('horus.config_cache_ttl_seconds', 60)],
        );
    }

    private function nextVersion(Site $site, ConfigEnvironment $environment): int
    {
        $query = ConfigVersion::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('environment', $environment->value);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        return ((int) $query->max('version')) + 1;
    }

}
