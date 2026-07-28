<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Models\GamConnectionPermission;
use App\Services\Gam\Contracts\GamConnectorInterface;

final class GamPermissionValidator
{
    public function validate(GamConnection $connection, GamConnectorInterface $connector): array
    {
        $current = $connector->getCurrentNetwork();
        $accessible = $current->success ? $connector->listAccessibleNetworks() : $current;

        $checks = [
            'api.access' => [
                'status' => $current->success ? 'GRANTED' : 'DENIED',
                'details' => $current->success ? ['network_code' => data_get($current->data, 'networkCode')] : ['error' => $current->errorCode],
            ],
            'network.read' => [
                'status' => $accessible->success ? 'GRANTED' : 'DENIED',
                'details' => $accessible->success ? ['accessible' => true] : ['error' => $accessible->errorCode],
            ],
        ];

        foreach ($checks as $permission => $result) {
            GamConnectionPermission::withoutGlobalScopes()->updateOrCreate(
                ['gam_connection_id' => $connection->id, 'permission_name' => $permission],
                [
                    'organization_id' => $connection->organization_id,
                    'status' => $result['status'],
                    'details' => $result['details'],
                    'verified_at' => now(),
                ],
            );
        }

        return $checks;
    }
}
