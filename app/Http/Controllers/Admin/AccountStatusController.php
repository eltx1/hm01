<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Advertiser;
use App\Models\Organization;
use App\Models\Publisher;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountStatusController extends Controller
{
    public function organization(Request $request, Organization $organization, AuditRecorder $audit, SessionInvalidator $sessions): RedirectResponse
    {
        $status = $this->validatedStatus($request);
        $before = $organization->status->value;
        $organization->update(['status' => $status]);
        if ($status !== AccountStatus::Active) {
            $organization->users()->each(fn ($user) => $sessions->invalidate($user));
        }
        $this->audit($audit, $request, $organization, 'organization.status.changed', $before, $status);

        return back();
    }

    public function publisher(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        return $this->updateAccount($request, $publisher, $audit, 'publisher.status.changed');
    }

    public function advertiser(Request $request, Advertiser $advertiser, AuditRecorder $audit): RedirectResponse
    {
        return $this->updateAccount($request, $advertiser, $audit, 'advertiser.status.changed');
    }

    private function updateAccount(Request $request, Model $account, AuditRecorder $audit, string $event): RedirectResponse
    {
        $status = $this->validatedStatus($request);
        $before = $account->status->value;
        $account->update(['status' => $status]);
        $this->audit($audit, $request, $account, $event, $before, $status);

        return back();
    }

    private function validatedStatus(Request $request): AccountStatus
    {
        $value = $request->validate(['status' => ['required', 'in:PENDING,ACTIVE,SUSPENDED,CLOSED']])['status'];

        return AccountStatus::from($value);
    }

    private function audit(AuditRecorder $audit, Request $request, Model $subject, string $event, string $before, AccountStatus $status): void
    {
        $audit->record($event, $subject->organization_id ?? $subject->id, $request->user(), $subject, ['status' => $before], ['status' => $status->value]);
    }
}
