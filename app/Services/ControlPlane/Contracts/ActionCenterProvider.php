<?php

namespace App\Services\ControlPlane\Contracts;

use App\Models\User;

interface ActionCenterProvider
{
    /** @return array<int, array<string, mixed>> */
    public function actions(User $user): array;
}
