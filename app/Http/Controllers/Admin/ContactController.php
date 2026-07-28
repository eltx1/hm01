<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertiser;
use App\Models\AdvertiserContact;
use App\Models\Publisher;
use App\Models\PublisherContact;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function storePublisher(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $contact = PublisherContact::withoutGlobalScopes()->create(array_merge($this->validated($request), ['organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id]));
        $audit->record('publisher.contact.created', $publisher->organization_id, $request->user(), $contact, newValues: $contact->only(['name', 'email', 'title']));

        return back();
    }

    public function updatePublisher(Request $request, Publisher $publisher, PublisherContact $contact, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($contact->publisher_id === $publisher->id, 404);
        $before = $contact->only(['name', 'email', 'phone', 'title', 'is_primary']);
        $contact->update($this->validated($request));
        $audit->record('publisher.contact.updated', $publisher->organization_id, $request->user(), $contact, $before, $contact->only(array_keys($before)));

        return back();
    }

    public function destroyPublisher(Request $request, Publisher $publisher, PublisherContact $contact, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($contact->publisher_id === $publisher->id, 404);
        $audit->record('publisher.contact.deleted', $publisher->organization_id, $request->user(), $contact, oldValues: $contact->only(['name', 'email']));
        $contact->delete();

        return back();
    }

    public function storeAdvertiser(Request $request, Advertiser $advertiser, AuditRecorder $audit): RedirectResponse
    {
        $contact = AdvertiserContact::withoutGlobalScopes()->create(array_merge($this->validated($request), ['organization_id' => $advertiser->organization_id, 'advertiser_id' => $advertiser->id]));
        $audit->record('advertiser.contact.created', $advertiser->organization_id, $request->user(), $contact, newValues: $contact->only(['name', 'email', 'title']));

        return back();
    }

    public function updateAdvertiser(Request $request, Advertiser $advertiser, AdvertiserContact $contact, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($contact->advertiser_id === $advertiser->id, 404);
        $before = $contact->only(['name', 'email', 'phone', 'title', 'is_primary']);
        $contact->update($this->validated($request));
        $audit->record('advertiser.contact.updated', $advertiser->organization_id, $request->user(), $contact, $before, $contact->only(array_keys($before)));

        return back();
    }

    public function destroyAdvertiser(Request $request, Advertiser $advertiser, AdvertiserContact $contact, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($contact->advertiser_id === $advertiser->id, 404);
        $audit->record('advertiser.contact.deleted', $advertiser->organization_id, $request->user(), $contact, oldValues: $contact->only(['name', 'email']));
        $contact->delete();

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'title' => ['nullable', 'string', 'max:100'], 'is_primary' => ['sometimes', 'boolean']]);
    }
}
