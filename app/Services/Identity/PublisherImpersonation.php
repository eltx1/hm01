<?php

namespace App\Services\Identity;

use App\Enums\{AccountStatus, OrganizationType, RoleName};
use App\Models\{Publisher, User};

final class PublisherImpersonation
{
    public function canStart(?User $actor): bool
    {
        return $actor && $actor->isActive() && $actor->hasVerifiedEmail()
            && $actor->isHorusAdministrator() && $actor->hasPermission('users.impersonate');
    }

    public function canBeTarget(User $target): bool
    {
        if (! $target->isActive() || ! $target->hasVerifiedEmail()
            || $target->organization?->type !== OrganizationType::Publisher
            || ! $target->hasPermission('dashboard.publisher.view')) {
            return false;
        }

        // A publisher identity carrying staff privileges is never a support-login target.
        $staffRoles = [RoleName::SuperAdmin, RoleName::OperationsAdmin, RoleName::AdOpsAdmin,
            RoleName::FinanceAdmin, RoleName::SupportAgent];
        foreach ($staffRoles as $role) {
            if ($target->hasRole($role->value)) return false;
        }
        foreach (['users.impersonate', 'dashboard.admin.view', 'roles.manage', 'organizations.manage'] as $permission) {
            if ($target->hasPermission($permission)) return false;
        }

        return Publisher::withoutGlobalScopes()->where('organization_id', $target->organization_id)
            ->where('status', AccountStatus::Active->value)->exists();
    }
}
