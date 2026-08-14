@php($asset = app(\App\Support\Branding\OfficialBrandAssets::class))
<x-brand.image :src="$asset->url('header_emblem')" alt="Horus Media emblem" fallback="Horus Media" :width="$asset->metadata('header_emblem')['width']" :height="$asset->metadata('header_emblem')['height']" {{ $attributes->class('brand-header-emblem') }} />
