<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Role;
use App\Services\Identity\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function create(): View
    {
        return view('admin.invitations.create', ['roles' => Role::where('is_system', true)->orderBy('name')->get()]);
    }

    public function store(Request $request, InvitationService $service): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'exists:organizations,id'], 'role_id' => ['nullable', 'exists:roles,id'], 'email' => ['required', 'email']]);
        $organization = Organization::findOrFail($data['organization_id']);
        abort_unless($request->user()->isHorusAdministrator() || $organization->id === $request->user()->organization_id, 403);
        $role = isset($data['role_id']) ? Role::findOrFail($data['role_id']) : null;
        $service->issue($organization, $data['email'], $role, $request->user());

        return back()->with('status', 'Invitation sent.');
    }

    public function show(string $token): View
    {
        return view('auth.accept-invitation', compact('token'));
    }

    public function accept(Request $request, string $token, InvitationService $service): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'confirmed', 'min:12']]);
        $user = $service->accept($token, $data['name'], $data['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
