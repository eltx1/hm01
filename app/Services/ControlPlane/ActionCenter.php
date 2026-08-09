<?php

namespace App\Services\ControlPlane;

use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;

final class ActionCenter
{
    /** @param iterable<ActionCenterProvider> $providers */
    public function __construct(private readonly iterable $providers) {}

    /** @return array<int, array<string, mixed>> */
    public function items(User $user): array
    {
        $user->loadMissing('roles.permissions');
        $items = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->actions($user) as $item) {
                if (($item['count'] ?? 0) > 0) {
                    $items[] = $item;
                }
            }
        }

        usort($items, fn (array $left, array $right): int => [$left['priority'], $left['label']] <=> [$right['priority'], $right['label']]);

        return $items;
    }
}
