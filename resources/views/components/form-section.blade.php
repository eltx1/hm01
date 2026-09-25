@props(['title', 'description' => null, 'number' => null])
<section {{ $attributes->class(['ui-form-section']) }}>
    <header class="ui-section-heading">
        @if($number)<span class="ui-section-number" aria-hidden="true">{{ $number }}</span>@endif
        <div><h2>{{ $title }}</h2>@if($description)<p>{{ $description }}</p>@endif</div>
    </header>
    {{ $slot }}
</section>
