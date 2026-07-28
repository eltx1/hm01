<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RolePermissionController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', ['roles' => Role::with('permissions')->orderBy('name')->get(), 'permissions' => Permission::orderBy('group')->orderBy('name')->get()]);
    }

    public function assignRole(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->isHorusAdministrator(), 403);
        $data = $request->validate(['role_id' => ['required', 'exists:roles,id']]);
        $role = Role::findOrFail($data['role_id']);
        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $request->user()->id]]);
        $audit->record('permission.role.assigned', $user->organization_id, $request->user(), $user, newValues: ['role' => $role->name]);

        return back();
    }

    public function syncPermissions(Request $request, Role $role, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->isHorusAdministrator(), 403);
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['exists:permissions,id']]);
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->permissions()->sync($data['permissions'] ?? []);
        $after = $role->permissions()->pluck('name')->sort()->values()->all();
        $audit->record('permission.role.updated', $request->user()->organization_id, $request->user(), $role, ['permissions' => $before], ['permissions' => $after]);

        return back();
    }
}
