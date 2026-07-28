<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Database\Seeders\IdentityAccessSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSuperAdministrator extends Command
{
    protected $signature = 'horus:create-super-admin {email?} {--name=}';

    protected $description = 'Securely create the initial Horus Media super administrator';

    public function handle(AuditRecorder $audit): int
    {
        $email = str($this->argument('email') ?: $this->ask('Email address'))->lower()->trim()->value();
        $name = $this->option('name') ?: $this->ask('Full name');
        $password = $this->secret('Password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $name) < 2 || strlen((string) $password) < 12 || ! hash_equals((string) $password, (string) $confirmation)) {
            $this->error('Invalid email, name, password length, or confirmation.');

            return self::FAILURE;
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => IdentityAccessSeeder::class, '--force' => true]);
        $organization = Organization::firstOrCreate(
            ['type' => OrganizationType::HorusMedia],
            ['name' => 'Horus Media', 'slug' => 'horus-media', 'status' => AccountStatus::Active, 'dashboard_title' => 'Horus Media'],
        );
        $user = User::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => UserStatus::Active,
            'activated_at' => now(),
            'email_verified_at' => now(),
        ]);
        $role = Role::whereNull('organization_id')->where('name', RoleName::SuperAdmin->value)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id]);
        $audit->record('bootstrap.super_admin.created', $organization->id, $user, $user, metadata: ['source' => 'artisan'], request: Request::create('/artisan/horus:create-super-admin', 'CLI', server: ['HTTP_X_REQUEST_ID' => (string) Str::uuid()]));

        $this->info('Super administrator created. Two-factor enrollment is required on first sign-in.');

        return self::SUCCESS;
    }
}
