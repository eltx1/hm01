<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\AccountSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function revoke(
        Request $request,
        string $reference,
        AccountSessionService $sessions,
        AuditRecorder $audit,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($sessions->revoke($user, $reference, $request->session()->getId()), 404);

        $audit->record(
            'account.session.revoked',
            $user->organization_id,
            $user,
            $user,
            metadata: ['scope' => 'owned_other_session'],
            request: $request,
        );

        return redirect()->route('account.security')->with('status', 'Session signed out.');
    }

    public function revokeOthers(
        Request $request,
        AccountSessionService $sessions,
        AuditRecorder $audit,
    ): RedirectResponse {
        $user = $request->user();
        $revoked = $sessions->revokeOthers($user, $request->session()->getId());
        $audit->record(
            'account.sessions.revoked_other',
            $user->organization_id,
            $user,
            $user,
            metadata: ['revoked_count' => $revoked],
            request: $request,
        );

        return redirect()->route('account.security')->with('status', $revoked === 1 ? '1 other session signed out.' : $revoked.' other sessions signed out.');
    }
}
