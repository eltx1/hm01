<?php

namespace App\Http\Controllers;

use App\Models\Advertiser;
use App\Models\Publisher;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function publisher(Request $request, Publisher $publisher): View
    {
        abort_unless($request->user()->isHorusAdministrator() || $publisher->organization_id === $request->user()->organization_id, 403);

        return view('accounts.publisher', compact('publisher'));
    }

    public function advertiser(Request $request, Advertiser $advertiser): View
    {
        abort_unless($request->user()->isHorusAdministrator() || $advertiser->organization_id === $request->user()->organization_id, 403);

        return view('accounts.advertiser', compact('advertiser'));
    }
}
