@props(['path', 'filters', 'counts', 'statuses', 'searchLabel' => 'Search', 'places' => null])
<div class="admin-queue-filters">
    <nav class="admin-queue-tabs" aria-label="Review status">
        @foreach(array_merge(['all'], $statuses) as $status)
            <?php $params = array_filter(['status' => $status === 'all' ? null : $status, 'q' => $filters['q'] ?? null, 'location' => $filters['location'] ?? null], fn ($value) => $value !== null && $value !== ''); ?>
            <a class="seller-status-tile" href="{{ $path }}{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst($status) }}</span></a>
        @endforeach
    </nav>
    <form class="panel seller-listing-search" method="get" action="{{ $path }}">
        @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
        <label class="sr-only" for="queue-search">{{ $searchLabel }}</label><input id="queue-search" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="{{ $searchLabel }}">@if($places !== null)<label>Community<select name="location"><option value="">All communities</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>@endif<button class="button button-primary">Search</button><a class="text-link" href="{{ $path }}">Reset</a>
    </form>
</div>
