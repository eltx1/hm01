<?php

namespace Tests\Concerns;

use App\Enums\GamConnectionType;
use App\Enums\GamCredentialType;
use App\Enums\GamHealthStatus;
use App\Models\GamConnection;
use App\Models\Organization;
use App\Models\User;

trait InteractsWithGam
{
    protected function makeGamConnection(Organization $organization, User $actor, array $attributes = []): GamConnection
    {
        $type = $attributes['type'] ?? GamConnectionType::HorusGam;
        $type = $type instanceof GamConnectionType ? $type : GamConnectionType::from($type);

        $connection = GamConnection::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => fake()->unique()->company().' GAM',
            'type' => $type,
            'credential_type' => GamCredentialType::ServiceAccount,
            'driver' => 'SOAP',
            'network_code' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'application_name' => 'Horus Media Test',
            'is_primary' => $type === GamConnectionType::HorusGam,
            'is_enabled' => true,
            'dry_run_default' => true,
            'health_status' => GamHealthStatus::Unknown,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $attributes));

        $connection->credential()->create([
            'organization_id' => $organization->id,
            'credential_type' => $connection->credential_type,
            'reference' => 'env:GAM_TEST_CREDENTIAL_PATH',
            'client_email_hint' => 'test@project.iam.gserviceaccount.com',
            'scopes' => [config('gam.oauth.scope')],
            'rotated_at' => now(),
        ]);

        return $connection->refresh()->load('credential');
    }
}
