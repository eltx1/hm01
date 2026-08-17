<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Identity\AccountSessionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AccountController extends Controller
{
    public function __invoke(Request $request, AccountSessionService $sessions): View
    {
        $user = $request->user();
        $activeSessions = $sessions->sessionsFor(
            $user,
            $request->session()->getId(),
            $request->userAgent(),
        );

        return view('account.index', [
            'user' => $user,
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            'sessionCount' => count($activeSessions),
        ]);
    }
}
