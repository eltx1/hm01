<?php

namespace App\Services\Inventory;

use App\Enums\ConfigEnvironment;
use App\Enums\ConfigVersionStatus;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SiteConfigPublisher
{
    public function __construct(
        private readonly SiteConfigurationBuilder $builder,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function preview(Site $site, ConfigEnvironment $environment): array
    {
        return $this->builder->build($site->refresh(), $environment, $this->nextVersion($site, $environment));
    }

    public function publish(Site $site, ConfigEnvironment $environment, User $actor): ConfigVersion
    {
        return DB::transaction(function () use ($site, $environment, $actor): ConfigVersion {
            $siteConfig = $this->ensureSiteConfig($site);
            $version = $this->nextVersion($site, $environment);
            $payload = $this->builder->build($site->refresh(), $environment, $version);
            $encoded = $this->encode($payload);
            $paths = $this->writeFiles($site, $environment, $version, $encoded);

            $record = ConfigVersion::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'site_config_id' => $siteConfig->id,
                'environment' => $environment,
                'version' => $version,
                'status' => ConfigVersionStatus::Published,
                'payload' => $payload,
                'checksum' => hash('sha256', $encoded),
                'file_path' => $paths['versioned_relative'],
                'created_by' => $actor->id,
                'published_at' => now(),
            ]);

            $siteConfig->update([$this->versionColumn($environment) => $version]);
            $settings = $site->servingSettings()->first();
            if ($settings) {
                $settings->update(['configuration_version' => max(((int) $settings->configuration_version) + 1, $version)]);
            }

            $this->audit->record('site.config.published', $site->organization_id, $actor, $record, newValues: [
                'site_id' => $site->id,
                'environment' => $environment->value,
                'version' => $version,
                'checksum' => $record->checksum,
                'file_path' => $record->file_path,
            ]);

            return $record;
        });
    }

    public function rollback(Site $site, ConfigEnvironment $environment, ConfigVersion $target, User $actor): ConfigVersion
    {
        if ($target->site_id !== $site->id || $target->environment !== $environment) {
            throw new RuntimeException('The selected configuration version does not belong to this site and environment.');
        }

        return DB::transaction(function () use ($site, $environment, $target, $actor): ConfigVersion {
            $siteConfig = $this->ensureSiteConfig($site);
            $version = $this->nextVersion($site, $environment);
            $payload = $target->payload;
            $payload['configVersion'] = $version;
            $payload['generatedAt'] = now()->utc()->toIso8601String();
            $payload['rollbackSourceVersion'] = $target->version;
            $encoded = $this->encode($payload);
            $paths = $this->writeFiles($site, $environment, $version, $encoded);

            ConfigVersion::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->where('environment', $environment->value)
                ->where('status', ConfigVersionStatus::Published->value)
                ->update(['status' => ConfigVersionStatus::RolledBack->value, 'rolled_back_at' => now()]);

            $record = ConfigVersion::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'site_config_id' => $siteConfig->id,
                'source_version_id' => $target->id,
                'environment' => $environment,
                'version' => $version,
                'status' => ConfigVersionStatus::Published,
                'payload' => $payload,
                'checksum' => hash('sha256', $encoded),
                'file_path' => $paths['versioned_relative'],
                'created_by' => $actor->id,
                'published_at' => now(),
            ]);

            $siteConfig->update([$this->versionColumn($environment) => $version]);
            $this->audit->record('site.config.rolled_back', $site->organization_id, $actor, $record, newValues: [
                'site_id' => $site->id,
                'environment' => $environment->value,
                'new_version' => $version,
                'source_version' => $target->version,
                'checksum' => $record->checksum,
            ]);

            return $record;
        });
    }

    public function pauseImmediately(Site $site, User $actor): ConfigVersion
    {
        $this->ensureSiteConfig($site)->update(['immediate_pause' => true, 'status' => 'PAUSED']);

        return $this->publish($site, ConfigEnvironment::Production, $actor);
    }

    public function resume(Site $site, User $actor): ConfigVersion
    {
        $this->ensureSiteConfig($site)->update(['immediate_pause' => false, 'status' => 'ACTIVE']);

        return $this->publish($site, ConfigEnvironment::Production, $actor);
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
        return ((int) ConfigVersion::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('environment', $environment->value)
            ->max('version')) + 1;
    }

    private function writeFiles(Site $site, ConfigEnvironment $environment, int $version, string $encoded): array
    {
        $root = rtrim((string) config('horus.static_config_root'), DIRECTORY_SEPARATOR);
        $directory = $root.DIRECTORY_SEPARATOR.$site->public_key;
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the static configuration directory.');
        }

        $environmentName = strtolower($environment->value);
        $versioned = $directory.DIRECTORY_SEPARATOR.$environmentName.'.v'.$version.'.json';
        $current = $directory.DIRECTORY_SEPARATOR.$environmentName.'.json';
        $this->atomicWrite($versioned, $encoded);
        $this->atomicWrite($current, $encoded);

        $publicBase = trim((string) config('horus.static_config_public_path', 'configs'), '/');

        return [
            'versioned_relative' => $publicBase.'/'.$site->public_key.'/'.$environmentName.'.v'.$version.'.json',
            'current_relative' => $publicBase.'/'.$site->public_key.'/'.$environmentName.'.json',
        ];
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically publish the static configuration file.');
        }
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function versionColumn(ConfigEnvironment $environment): string
    {
        return match ($environment) {
            ConfigEnvironment::Preview => 'preview_version',
            ConfigEnvironment::Test => 'test_version',
            ConfigEnvironment::Production => 'production_version',
        };
    }
}
