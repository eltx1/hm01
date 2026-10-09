@if ($paginator->hasPages())
<nav class="hm-pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
    <div class="hm-pagination-controls">
        @if ($paginator->onFirstPage())
            <span class="hm-pagination-link hm-pagination-direction" aria-disabled="true">{{ __('Previous') }}</span>
        @else
            <a class="hm-pagination-link hm-pagination-direction" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="hm-pagination-link hm-pagination-direction" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>
        @else
            <span class="hm-pagination-link hm-pagination-direction" aria-disabled="true">{{ __('Next') }}</span>
        @endif
    </div>
</nav>
@endif
