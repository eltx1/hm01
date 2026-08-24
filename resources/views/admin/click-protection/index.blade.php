@extends('layouts.admin')
@section('title', 'Click Protection')
@section('heading', 'Global Click Protection')
@section('content')
@if(session('status'))<section><p>{{ session('status') }}</p></section>@endif
<section>
    <p class="eyebrow">One policy for every website</p>
    <h2>Browser-local repeated-click protection</h2>
    <p>Websites use this policy automatically unless an administrator creates an explicit website override. Saving a change republishes every active website configuration; inactive websites receive it automatically when activated.</p>
    <p class="muted">This is a lightweight browser heuristic stored in localStorage. It stops new ad requests in that browser after the threshold; it is not server-side click tracking or authoritative IVT classification.</p>
    <div class="metric-grid">
        <article><span>All websites</span><strong>{{ $websiteCount }}</strong></article>
        <article><span>Active websites</span><strong>{{ $activeWebsiteCount }}</strong></article>
        <article><span>Explicit overrides</span><strong>{{ $overrideCount }}</strong></article>
    </div>
</section>

<section>
    <h2>Global policy</h2>
    @can('settings.manage')
    <form class="form-stack safe-submit" method="POST" action="{{ route('admin.click-protection.update') }}">@csrf @method('PUT')
        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $policy['enabled']))> Enable for websites using the global policy</label>
        <label>Maximum detected ad clicks<input class="hm-input" type="number" min="1" max="50" name="max_clicks" value="{{ old('max_clicks', $policy['maxClicks']) }}" required></label>
        <label>Rolling window (hours)<input class="hm-input" type="number" min="1" max="168" name="window_hours" value="{{ old('window_hours', $policy['windowHours']) }}" required></label>
        <label>Stop new ads for (hours)<input class="hm-input" type="number" min="1" max="720" name="block_hours" value="{{ old('block_hours', $policy['blockHours']) }}" required></label>
        <label>Reason<textarea class="hm-input" name="reason" maxlength="500" required placeholder="Why this global delivery change is needed"></textarea></label>
        <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Confirmation<input class="hm-input" name="impact_confirmation" autocomplete="off" required placeholder="CHANGE CLICK PROTECTION"></label>
        <button class="hm-button-primary">Save and republish active websites</button>
    </form>
    @else
    <p class="muted">You have read-only access to this policy.</p>
    @endcan
</section>
@endsection
