<?php

use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            'publisher_quality.review' => ['Record human Publisher quality decisions', 'publishers'],
            'publisher_quality.ai.run' => ['Run THOTH Publisher quality advisories', 'publishers'],
            'thoth.settings.view' => ['View THOTH AI settings and health', 'settings'],
            'thoth.settings.manage' => ['Manage THOTH AI provider and runtime settings', 'settings'],
            'thoth.credentials.manage' => ['Replace encrypted THOTH provider credentials', 'settings'],
        ];

        foreach ($definitions as $name => [$displayName, $group]) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'name' => $name,
                    'display_name' => $displayName,
                    'group' => $group,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $rolesExist = DB::table('roles')->exists();
        $roleGrants = [
            RoleName::SuperAdmin->value => array_keys($definitions),
            RoleName::OperationsAdmin->value => [
                'publisher_quality.review', 'publisher_quality.ai.run',
                'thoth.settings.view', 'thoth.settings.manage',
            ],
            RoleName::AdOpsAdmin->value => [
                'publisher_quality.review', 'publisher_quality.ai.run', 'thoth.settings.view',
            ],
        ];

        foreach ($roleGrants as $roleName => $permissionNames) {
            $roleId = DB::table('roles')->whereNull('organization_id')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        if (! $rolesExist) {
            return;
        }

        foreach (['OPENAI' => 'gpt-5-mini', 'GEMINI' => 'gemini-2.5-flash'] as $provider => $model) {
            if (! DB::table('ai_provider_connections')->where('provider', $provider)->exists()) {
                DB::table('ai_provider_connections')->insert([
                    'id' => (string) Str::ulid(),
                    'provider' => $provider,
                    'model' => $model,
                    'credential_source' => 'NONE',
                    'status' => 'NOT_CONFIGURED',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('thoth_settings')->insertOrIgnore([
            'id' => 1,
            'enabled' => false,
            'active_provider' => 'OPENAI',
            'timeout_seconds' => 20,
            'max_output_tokens' => 1800,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Access repair is intentionally durable. Rolling code back must not revoke
        // existing administrator permissions or delete configured provider state.
    }
};
