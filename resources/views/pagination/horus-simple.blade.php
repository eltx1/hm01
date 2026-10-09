@if ($paginator->hasPages())
<nav class="hm-pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
    <div class="hm-pagination-controls">
        @if ($paginator->onFirstPage())
            <span class="hm-pagination-link hm-pagination-direction" aria-disabled="true">{{ __('pagination.previous') }}</span>
        @else
            <a class="hm-pagination-link hm-pagination-direction" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('pagination.previous') }}</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="hm-pagination-link hm-pagination-direction" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }}</a>
        @else
            <span class="hm-pagination-link hm-pagination-direction" aria-disabled="true">{{ __('pagination.next') }}</span>
        @endif
    </div>
</nav>
@endif
