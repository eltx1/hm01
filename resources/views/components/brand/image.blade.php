@props(['src' => null, 'alt', 'fallback' => 'Horus Media', 'width' => null, 'height' => null])
@if($src)
<img src="{{ $src }}" alt="{{ $alt }}" @if($width)width="{{ $width }}"@endif @if($height)height="{{ $height }}"@endif decoding="async" {{ $attributes->class('brand-image') }}>
@else
<span role="img" aria-label="{{ $alt }}" {{ $attributes->class(['brand-image', 'brand-image-fallback']) }}><span aria-hidden="true">HM</span><span class="sr-only">{{ $fallback }}</span></span>
@endif
