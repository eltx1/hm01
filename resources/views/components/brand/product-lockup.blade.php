@props(['context' => 'workspace', 'variant' => 'emblem', 'href' => null, 'compact' => false])
@php
    $resolver = app(\App\Support\Branding\BrandIdentityResolver::class);
    $identity = $context === 'horus' ? $resolver->official($variant) : $resolver->forWorkspace(auth()->user(), $variant);
    $classes = $attributes->class(['brand-product-lockup', 'brand-product-lockup-compact' => $compact, 'brand-product-lockup-tenant' => $identity->usesTenantLogo]);
@endphp
@if($href)<a href="{{ $href }}" aria-label="{{ $identity->name }} home" {{ $classes }} data-brand-source="{{ $identity->usesTenantLogo ? 'tenant' : 'horus' }}">@else<div {{ $classes }} data-brand-source="{{ $identity->usesTenantLogo ? 'tenant' : 'horus' }}">@endif
    <x-brand.image :src="$identity->logoUrl" :alt="$identity->logoAlt" :fallback="$identity->name" class="brand-product-mark" />
    @unless($compact)<span class="brand-product-copy"><strong>{{ $identity->name }}</strong><small>{{ $identity->descriptor }}</small></span>@endunless
@if($href)</a>@else</div>@endif
