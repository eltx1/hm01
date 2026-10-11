@extends('layouts.admin')
@section('title', 'Add mobile app')
@section('heading', 'Add mobile app')
@section('content')
<section class="hero"><div><p class="eyebrow">Mobile apps</p><h2>Connect your store listing</h2><p>Supported stores: {{ implode(', ', config('mobile-apps.stores')) }}.</p></div></section>
<article class="workspace-section ui-form-surface"><form class="form-stack" method="post" action="{{ route($prefix.'.store') }}">@csrf
@if($publishers->isNotEmpty())<label>Publisher<select class="hm-input" name="publisher_id" required>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}">{{ $publisher->display_name }}</option>@endforeach</select></label>@endif
<label>App name<input class="hm-input" name="name" value="{{ old('name') }}" maxlength="255" required></label>
<label>Store URL<input class="hm-input" name="store_url" type="url" value="{{ old('store_url') }}" placeholder="https://play.google.com/store/apps/details?id=com.company.app" required></label>
<label>Android package name<input class="hm-input" name="package_name" value="{{ old('package_name') }}" placeholder="com.company.app"><small>Google Play and Apple IDs are detected from the URL. Other Android stores may require the package name.</small></label>
<label>Developer website root domain<input class="hm-input" name="developer_domain" value="{{ old('developer_domain') }}" placeholder="example.com" required><small>This website must be listed as the developer website on the store page. You will host app-ads.txt at its root.</small></label>
<div class="button-row"><button class="hm-button-primary">Submit app</button><a class="hm-button-secondary button-link" href="{{ route($prefix.'.index') }}">Back</a></div></form></article>
@endsection
