<?php

namespace Tests\Concerns;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityAccessSeeder;

trait InteractsWithIdentity
{
    protected function seedIdentity(): void
    {
        $this->seed(IdentityAccessSeeder::class);
    }

    protected function makeOrganization(OrganizationType $type, ?string $name = null): Organization
    {
        return Organization::create([
            'name' => $name ?? str($type->value)->headline(),
            'slug' => fake()->unique()->slug(),
            'type' => $type,
            'status' => AccountStatus::Active,
        ]);
    }

    protected function makeUser(Organization $organization, RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'status' => UserStatus::Active,
            'activated_at' => now(),
        ], $attributes));
        $systemRole = Role::whereNull('organization_id')->where('name', $role->value)->firstOrFail();
        $user->roles()->attach($systemRole);

        return $user;
    }
}
