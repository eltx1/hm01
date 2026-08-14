@php($asset = app(\App\Support\Branding\OfficialBrandAssets::class))
<x-brand.image :src="$asset->url('emblem')" alt="Horus Media emblem" fallback="Horus Media" :width="$asset->metadata('emblem')['width']" :height="$asset->metadata('emblem')['height']" {{ $attributes->class('brand-emblem') }} />
