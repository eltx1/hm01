<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublisherPaymentProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePublisherPaymentProfileRequest;
use App\Models\Publisher;
use App\Models\PublisherPaymentProfile;
use App\Services\Reporting\PublisherPaymentProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublisherPaymentProfileController extends Controller
{
    public function edit(Publisher $publisher): View
    {
        return view('admin.publishers.payment-profile', ['publisher' => $publisher, 'profile' => $publisher->paymentProfile]);
    }

    public function update(
        SavePublisherPaymentProfileRequest $request,
        Publisher $publisher,
        PublisherPaymentProfileService $profiles,
    ): RedirectResponse {
        $profiles->save($publisher, $request->validated(), $request->user());

        return back()->with('status', 'Payment profile updated. Sensitive values remain encrypted and masked.');
    }

    public function review(
        Request $request,
        Publisher $publisher,
        PublisherPaymentProfileService $profiles,
    ): RedirectResponse {
        $profile = PublisherPaymentProfile::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('organization_id', $publisher->organization_id)
            ->firstOrFail();
        $data = $request->validate([
            'verification_status' => ['required', 'in:VERIFIED,REJECTED,PENDING_VERIFICATION'],
            'verification_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $profiles->review(
            $profile,
            PublisherPaymentProfileStatus::from($data['verification_status']),
            $request->user(),
            $data['verification_reason'] ?? null,
        );

        return back()->with('status', 'Payment profile verification state updated.');
    }
}
