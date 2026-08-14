@php($asset = app(\App\Support\Branding\OfficialBrandAssets::class))
<x-brand.image :src="$asset->url('full_logo')" alt="Horus Media official logo" fallback="Horus Media" :width="$asset->metadata('full_logo')['width']" :height="$asset->metadata('full_logo')['height']" {{ $attributes->class('brand-full-logo') }} />
