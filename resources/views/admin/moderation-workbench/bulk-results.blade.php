<x-layouts.admin title="Bulk review results">
    <div class="page-intro"><div><h1>Bulk review results</h1><p>{{ collect($results)->where('success', true)->count() }} decision saved / {{ collect($results)->where('success', false)->count() }} skipped</p></div></div>
    <div class="panel">
        <ul class="notification-list">
            @foreach($results as $result)
                <li class="item-row"><strong>{{ $result['id'] ? 'Submission #'.$result['id'] : 'Submission '.$loop->iteration }}</strong><span>{{ $result['message'] }}</span></li>
            @endforeach
        </ul>
    </div>
    <p><a class="text-link" href="{{ route('moderation.index') }}">Back to moderation</a></p>
</x-layouts.admin>
