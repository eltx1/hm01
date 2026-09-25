@props(['help' => null, 'cancelHref' => null])
<div {{ $attributes->class(['ui-form-actions']) }}>
    @if($help)<p>{{ $help }}</p>@endif
    <div class="ui-action-buttons">
        @if($cancelHref)<a class="hm-button-secondary" href="{{ $cancelHref }}">Cancel</a>@endif
        {{ $slot }}
    </div>
</div>
