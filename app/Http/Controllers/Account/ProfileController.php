<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    public function update(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $oldName = $user->name;
        $newName = trim($data['name']);

        if ($newName === '') {
            return back()->withErrors(['name' => 'Name is required.'])->withInput();
        }

        if ($newName !== $oldName) {
            $user->forceFill(['name' => $newName])->save();
            $audit->record(
                'account.profile.updated',
                $user->organization_id,
                $user,
                $user,
                oldValues: ['name' => $oldName],
                newValues: ['name' => $newName],
                request: $request,
            );
        }

        return redirect()->route('account.profile.edit')->with('status', 'Profile updated.');
    }
}
