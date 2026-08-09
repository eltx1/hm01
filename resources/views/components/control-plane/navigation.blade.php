@props(['groups' => []])

<nav class="control-navigation" aria-label="Control plane navigation">
    @foreach($groups as $group)
        <section class="navigation-group" aria-labelledby="navigation-{{ \Illuminate\Support\Str::slug($group['label']) }}">
            <p id="navigation-{{ \Illuminate\Support\Str::slug($group['label']) }}" class="navigation-label">{{ $group['label'] }}</p>
            <div class="navigation-links">
                @foreach($group['items'] as $item)
                    @php($active = collect($item['active'])->contains(fn ($pattern) => request()->routeIs($pattern)))
                    <a href="{{ route($item['route'], $item['parameters']) }}" @class(['active' => $active]) @if($active)aria-current="page"@endif>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</nav>
