@props(['name' => 'bank'])
<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
        @case('mail')<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/>@break
        @case('transfer')<path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4"/>@break
        @case('wallet')<path d="M20 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h15V8H5a2 2 0 0 1 0-4"/><path d="M20 12h-5v4h5"/>@break
        @default<path d="m3 8 9-5 9 5H3Zm0 13h18M5 11v6m7-6v6m7-6v6"/>
    @endswitch
</svg>
