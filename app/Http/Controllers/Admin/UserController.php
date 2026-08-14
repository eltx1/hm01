<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublisherApplicationStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function activate(User $user, Request $request, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeOrganization($request, $user);
        $before = ['status' => $user->status->value];
        $user->update(['status' => UserStatus::Active, 'activated_at' => now(), 'suspended_at' => null]);
        $audit->record('user.activated', $user->organization_id, $request->user(), $user, $before, ['status' => UserStatus::Active->value]);

        return back();
    }

    public function suspend(User $user, Request $request, SessionInvalidator $sessions, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeOrganization($request, $user);
        abort_if($user->is($request->user()), 422, 'You cannot suspend yourself.');
        $before = ['status' => $user->status->value];
        $user->update(['status' => UserStatus::Suspended, 'suspended_at' => now()]);
        $sessions->invalidate($user);
        $audit->record('user.suspended', $user->organization_id, $request->user(), $user, $before, ['status' => UserStatus::Suspended->value]);

        return back();
    }

    public function destroy(User $user, Request $request, SessionInvalidator $sessions, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeOrganization($request, $user);
        abort_if($user->is($request->user()), 422, 'You cannot delete yourself.');
        if ($user->publisherApplication()->where('status', '!=', PublisherApplicationStatus::Approved->value)->exists()) {
            throw ValidationException::withMessages(['user' => 'A pending public applicant identity must be retained until an explicit application decision. Suspend it for a security response.']);
        }
        $sessions->invalidate($user);
        $audit->record('user.deleted', $user->organization_id, $request->user(), $user, oldValues: ['email' => $user->email]);
        $user->delete();

        return back();
    }

    private function authorizeOrganization(Request $request, User $target): void
    {
        abort_unless($request->user()->isHorusAdministrator() || $request->user()->organization_id === $target->organization_id, 403);
    }
}
