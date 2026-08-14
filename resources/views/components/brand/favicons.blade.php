@php($favicon = app(\App\Support\Branding\OfficialBrandAssets::class)->url('favicon'))
@if($favicon)
<link rel="icon" type="image/png" sizes="512x512" href="{{ $favicon }}">
<link rel="apple-touch-icon" sizes="512x512" href="{{ $favicon }}">
@endif
