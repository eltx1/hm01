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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class CreateSuperAdministrator extends Command
{
    protected $signature = 'horus:create-super-admin {email?} {--name=}';

    protected $description = 'Securely create the initial Horus Media super administrator';

    public function handle(AuditRecorder $audit): int
    {
        $email = str($this->argument('email') ?: $this->ask('Email address'))->lower()->trim()->value();
        $name = trim((string) ($this->option('name') ?: $this->ask('Full name')));
        $password = (string) $this->secret('Password (minimum 14 characters, mixed case, number, symbol)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'name' => ['required', 'string', 'min:2'],
                'password' => ['required', PasswordRule::min(14)->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails() || ! hash_equals($password, $confirmation)) {
            $this->error('Invalid email, name, password policy, or confirmation.');

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
