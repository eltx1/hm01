@if ($paginator->hasPages())
<nav class="hm-pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
    <p class="hm-pagination-summary">{{ __('Showing') }} <strong>{{ $paginator->firstItem() }}</strong> {{ __('to') }} <strong>{{ $paginator->lastItem() }}</strong> {{ __('of') }} <strong>{{ $paginator->total() }}</strong> {{ __('results') }}</p>
    <div class="hm-pagination-controls">
        @if ($paginator->onFirstPage())
            <span class="hm-pagination-link hm-pagination-direction" aria-disabled="true">{{ __('pagination.previous') }}</span>
        @else
            <a class="hm-pagination-link hm-pagination-direction" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('pagination.previous') }}</a>
        @endif
        <span class="hm-pagination-mobile">{{ __('Page') }} {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        <div class="hm-pagination-pages">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="hm-pagination-ellipsis" aria-hidden="true">{{ $element }}</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="hm-pagination-link" aria-current="page" aria-label="{{ __('Page :page', ['page' => $page]) }}">{{ $page }}</span>
                        @else
                            <a class="hm-pagination-link" href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>
        @if ($paginator->hasMorePages())
            <a class="hm-pagination-link hm-pagination-direction" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }}</a>
        @else
            <span class="hm-pagination-link hm-pagination-direction" aria-disabled="true">{{ __('pagination.next') }}</span>
        @endif
    </div>
</nav>
@endif
