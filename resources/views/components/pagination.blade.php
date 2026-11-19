@if($paginator->hasPages())
<nav class="pagination" aria-label="Pagination">
    @if($paginator->onFirstPage())<span>Previous</span>@else<a class="text-link" rel="prev" href="{{ $paginator->previousPageUrl() }}">Previous</a>@endif
    <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())<a class="text-link" rel="next" href="{{ $paginator->nextPageUrl() }}">Next</a>@else<span>Next</span>@endif
</nav>
@endif
