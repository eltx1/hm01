<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sites\StorePublisherSiteRequest;
use App\Models\Publisher;
use App\Models\Site;
use App\Services\Sites\SiteLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PublisherSiteController extends Controller
{
    public function create(Publisher $publisher): View
    {
        return view('publisher.sites.form', [
            'site' => new Site,
            'publisher' => $publisher,
            'adminContext' => true,
            'siteStoreRoute' => route('admin.publishers.sites.store', $publisher),
            'cancelRoute' => route('admin.publishers.show', $publisher).'#websites',
        ]);
    }

    public function store(
        StorePublisherSiteRequest $request,
        Publisher $publisher,
        SiteLifecycleService $lifecycle,
    ): RedirectResponse {
        $data = $request->sitePayload();
        $data['default_revenue_share_percent'] = $publisher->applicableRevenueShare();
        $site = $lifecycle->create(array_merge($data, [
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
        ]), $request->user());

        return redirect()->route('admin.sites.show', $site)->with(
            'status',
            'Website added to '.$publisher->display_name.'. It now follows the normal publisher ads.txt verification and review flow.',
        );
    }
}
