@props(['items' => [], 'label' => 'Workspace sections'])

<nav class="workspace-tabs" aria-label="{{ $label }}">
    @foreach($items as $item)
        @if($item['visible'] ?? true)
            @php($active = rtrim(request()->url(), '/') === rtrim(strtok($item['href'], '?'), '/'))
            <a href="{{ $item['href'] }}" @if($active)aria-current="page" class="active"@endif>{{ $item['label'] }}</a>
        @endif
    @endforeach
</nav>
