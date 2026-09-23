@props(['groups' => []])

<nav class="control-navigation" aria-label="Control plane navigation">
    @foreach($groups as $group)
        @php($groupActive = collect($group['items'])->contains(fn ($item) => collect($item['active'])->contains(fn ($pattern) => request()->routeIs($pattern))))
        @php($defaultOpen = $groupActive || $loop->first)
        <details class="navigation-group" data-nav-group data-default-open="{{ $defaultOpen ? 'true' : 'false' }}" @if($defaultOpen)open@endif>
            <summary class="navigation-label">{{ $group['label'] }}</summary>
            <div class="navigation-links">
                @foreach($group['items'] as $item)
                    @php($active = collect($item['active'])->contains(fn ($pattern) => request()->routeIs($pattern)))
                    <a href="{{ route($item['route'], $item['parameters']) }}" @class(['active' => $active]) @if($active)aria-current="page"@endif>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </details>
    @endforeach
</nav>
