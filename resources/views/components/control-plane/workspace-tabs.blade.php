@props(['items' => [], 'label' => 'Workspace sections'])

<nav class="workspace-tabs" aria-label="{{ $label }}">
    @foreach($items as $item)
        @if($item['visible'] ?? true)
            <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
        @endif
    @endforeach
</nav>
