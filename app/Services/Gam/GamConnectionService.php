<?php

namespace App\Services\Gam;

use App\Enums\GamConnectionType;
use App\Enums\GamHealthStatus;
use App\Enums\ServingMode;
use App\Models\GamConnection;
use App\Models\GamNetwork;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\Data\GamResult;
use App\Services\Sites\SiteLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GamConnectionService
{
    public function __construct(
        private readonly GamCredentialReferenceValidator $credentialValidator,
        private readonly GamConnectorManager $connectors,
        private readonly GamPermissionValidator $permissions,
        private readonly SiteLifecycleService $sites,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function create(array $data, User $actor): GamConnection
    {
        $this->credentialValidator->validate((string) $data['credential_reference']);

        return DB::transaction(function () use ($data, $actor): GamConnection {
            $type = GamConnectionType::from($data['type']);
            $shouldBePrimary = $type === GamConnectionType::HorusGam
                && ((bool) ($data['is_primary'] ?? false) || ! GamConnection::withoutGlobalScopes()->where('type', $type->value)->where('is_primary', true)->exists());

            if ($shouldBePrimary) {
                GamConnection::withoutGlobalScopes()->where('type', $type->value)->update(['is_primary' => false]);
            }

            $connection = GamConnection::withoutGlobalScopes()->create([
                'organization_id' => $data['organization_id'] ?? $actor->organization_id,
                'name' => $data['name'],
                'type' => $type,
                'credential_type' => $data['credential_type'],
                'driver' => strtoupper($data['driver'] ?? 'SOAP'),
                'network_code' => $data['network_code'] ?? null,
                'application_name' => $data['application_name'] ?? config('gam.application_name'),
                'is_primary' => $shouldBePrimary,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'dry_run_default' => (bool) ($data['dry_run_default'] ?? true),
                'health_status' => (bool) ($data['is_enabled'] ?? true) ? GamHealthStatus::Unknown : GamHealthStatus::Disabled,
                'configuration' => $data['configuration'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $connection->credential()->create([
                'organization_id' => $connection->organization_id,
                'credential_type' => $data['credential_type'],
                'reference' => $data['credential_reference'],
                'client_email_hint' => $data['client_email_hint'] ?? null,
                'oauth_client_id_hint' => $data['oauth_client_id_hint'] ?? null,
                'scopes' => $data['scopes'] ?? [config('gam.oauth.scope')],
                'rotated_at' => now(),
            ]);

            $this->audit->record('gam.connection.created', $connection->organization_id, $actor, $connection, newValues: [
                'name' => $connection->name,
                'type' => $connection->type->value,
                'network_code' => $connection->network_code,
                'is_primary' => $connection->is_primary,
                'credential_reference' => '[REDACTED]',
            ]);

            return $connection->load('credential');
        });
    }

    public function update(GamConnection $connection, array $data, User $actor): GamConnection
    {
        if (isset($data['credential_reference'])) {
            $this->credentialValidator->validate((string) $data['credential_reference']);
        }

        return DB::transaction(function () use ($connection, $data, $actor): GamConnection {
            $old = $connection->only(['name', 'type', 'network_code', 'is_primary', 'is_enabled', 'driver']);
            $type = isset($data['type']) ? GamConnectionType::from($data['type']) : $connection->type;
            $makePrimary = $type === GamConnectionType::HorusGam && (bool) ($data['is_primary'] ?? $connection->is_primary);

            if ($makePrimary) {
                GamConnection::withoutGlobalScopes()
                    ->where('type', GamConnectionType::HorusGam->value)
                    ->where('id', '!=', $connection->id)
                    ->update(['is_primary' => false]);
            }

            $connection->update([
                'name' => $data['name'] ?? $connection->name,
                'type' => $type,
                'credential_type' => $data['credential_type'] ?? $connection->credential_type,
                'driver' => strtoupper($data['driver'] ?? $connection->driver),
                'network_code' => array_key_exists('network_code', $data) ? $data['network_code'] : $connection->network_code,
                'application_name' => $data['application_name'] ?? $connection->application_name,
                'is_primary' => $makePrimary,
                'is_enabled' => (bool) ($data['is_enabled'] ?? $connection->is_enabled),
                'dry_run_default' => (bool) ($data['dry_run_default'] ?? $connection->dry_run_default),
                'health_status' => (bool) ($data['is_enabled'] ?? $connection->is_enabled) ? $connection->health_status : GamHealthStatus::Disabled,
                'configuration' => $data['configuration'] ?? $connection->configuration,
                'updated_by' => $actor->id,
            ]);

            $credential = $connection->credential()->firstOrFail();
            $credential->update([
                'credential_type' => $data['credential_type'] ?? $credential->credential_type,
                'reference' => $data['credential_reference'] ?? $credential->reference,
                'client_email_hint' => $data['client_email_hint'] ?? $credential->client_email_hint,
                'oauth_client_id_hint' => $data['oauth_client_id_hint'] ?? $credential->oauth_client_id_hint,
                'scopes' => $data['scopes'] ?? $credential->scopes,
                'rotated_at' => isset($data['credential_reference']) ? now() : $credential->rotated_at,
            ]);

            $this->audit->record('gam.connection.updated', $connection->organization_id, $actor, $connection, $old, [
                'name' => $connection->name,
                'type' => $connection->type->value,
                'network_code' => $connection->network_code,
                'is_primary' => $connection->is_primary,
                'is_enabled' => $connection->is_enabled,
                'driver' => $connection->driver,
                'credential_reference' => isset($data['credential_reference']) ? '[ROTATED]' : '[UNCHANGED]',
            ]);

            return $connection->refresh()->load('credential');
        });
    }

    public function setPrimary(GamConnection $connection, User $actor): GamConnection
    {
        if ($connection->type !== GamConnectionType::HorusGam) {
            throw ValidationException::withMessages(['connection' => 'Only a HORUS_GAM connection can be the primary Horus network.']);
        }

        return DB::transaction(function () use ($connection, $actor): GamConnection {
            GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::HorusGam->value)
                ->where('id', '!=', $connection->id)
                ->update(['is_primary' => false]);
            $connection->update(['is_primary' => true, 'is_enabled' => true, 'updated_by' => $actor->id]);
            $this->audit->record('gam.connection.primary_selected', $connection->organization_id, $actor, $connection, newValues: ['is_primary' => true]);

            return $connection->refresh();
        });
    }

    public function test(GamConnection $connection, User $actor): GamResult
    {
        $connector = $this->connectors->for($connection);
        $result = $connector->testConnection();
        $connection->update([
            'health_status' => $result->success ? GamHealthStatus::Healthy : GamHealthStatus::Failed,
            'last_health_check_at' => now(),
            'last_successful_sync_at' => $result->success ? now() : $connection->last_successful_sync_at,
            'updated_by' => $actor->id,
        ]);

        if ($result->success) {
            $this->persistNetwork($connection, $result->data, true);
            $accessible = $connector->listAccessibleNetworks();
            if ($accessible->success) {
                foreach ($this->networkList($accessible->data) as $network) {
                    $this->persistNetwork($connection, $network, (string) data_get($network, 'networkCode') === (string) $connection->network_code);
                }
            }
            $this->permissions->validate($connection, $connector);
        }

        $this->audit->record('gam.connection.tested', $connection->organization_id, $actor, $connection, newValues: [
            'health_status' => $connection->health_status->value,
            'success' => $result->success,
            'error_category' => $result->errorCategory,
            'error_code' => $result->errorCode,
        ]);

        return $result;
    }

    public function assignToSite(Site $site, GamConnection $connection, User $actor, string $reason): Site
    {
        if (! $connection->is_enabled) {
            throw ValidationException::withMessages(['gam_connection_id' => 'The selected GAM connection is disabled.']);
        }

        $mode = match ($connection->type) {
            GamConnectionType::HorusGam => ServingMode::HorusGam,
            GamConnectionType::McmPartnerGam => ServingMode::McmPartnerGam,
            GamConnectionType::PublisherGam => ServingMode::PublisherGam,
        };

        return DB::transaction(function () use ($site, $connection, $actor, $reason, $mode): Site {
            $previousConnection = $site->gam_connection_id;
            $site->update(['gam_connection_id' => $connection->id]);

            if ($site->serving_mode !== $mode) {
                $this->sites->changeServingMode($site, $mode, $actor, $reason);
            } else {
                $settings = $site->servingSettings()->firstOrFail();
                $settings->update(['configuration_version' => $settings->configuration_version + 1]);
            }

            $this->audit->record('gam.connection.assigned_to_site', $site->organization_id, $actor, $site, [
                'gam_connection_id' => $previousConnection,
            ], [
                'gam_connection_id' => $connection->id,
                'serving_mode' => $mode->value,
            ], ['reason' => $reason]);

            return $site->refresh()->load('gamConnection');
        });
    }

    private function persistNetwork(GamConnection $connection, array $network, bool $current): void
    {
        $network = data_get($network, 'rval', $network);
        if (! is_array($network)) {
            return;
        }

        $networkCode = data_get($network, 'networkCode') ?? $connection->network_code;
        if (! is_scalar($networkCode) || (string) $networkCode === '') {
            return;
        }

        if ($current) {
            GamNetwork::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->update(['is_current' => false]);
        }

        GamNetwork::withoutGlobalScopes()->updateOrCreate(
            ['gam_connection_id' => $connection->id, 'network_code' => (string) $networkCode],
            [
                'organization_id' => $connection->organization_id,
                'display_name' => data_get($network, 'displayName'),
                'currency_code' => data_get($network, 'currencyCode'),
                'time_zone' => data_get($network, 'timeZone'),
                'is_test' => (bool) data_get($network, 'isTest', false),
                'is_current' => $current,
                'capabilities' => data_get($network, 'capabilities'),
                'last_seen_at' => now(),
            ],
        );

        if (! $connection->network_code && $current) {
            $connection->update(['network_code' => (string) $networkCode]);
        }
    }

    private function networkList(array $data): array
    {
        foreach (['rval', 'results', 'networks', 'value'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_is_list($data[$key]) ? $data[$key] : [$data[$key]];
            }
        }

        return array_is_list($data) ? $data : [$data];
    }
}
