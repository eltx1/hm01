@props(['title', 'description' => null])

<section {{ $attributes->class('empty-state') }} aria-label="{{ $title }}">
    <h3>{{ $title }}</h3>
    @if($description)<p>{{ $description }}</p>@endif
    @if(trim((string) $slot) !== '')<div>{{ $slot }}</div>@endif
</section>
