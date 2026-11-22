<x-layouts.admin title="Preview report decisions">
    <div class="page-intro">
        <div><a class="text-link" href="{{ route('moderation.reports.index', ['type' => $type]) }}">Abuse reports</a><h1>Preview report decisions</h1></div>
    </div>
    <form method="post" action="{{ route('moderation.reports.bulk.resolve') }}" class="panel form-stack">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <h2>{{ \App\Support\ModerationReports::TYPES[$type] }}</h2>
        <ul>
            @foreach($preview as $item)
                <li>Report #{{ $item['id'] }} · {{ $item['version'] ? ($item['title'] ?? 'Source unavailable') : 'Report unavailable or already decided' }}</li>
            @endforeach
        </ul>
        @foreach($preview as $item)
            @if($item['version'])
                <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $item['id'] }}">
                <input type="hidden" name="items[{{ $loop->index }}][version]" value="{{ $item['version'] }}">
                <input type="hidden" name="items[{{ $loop->index }}][proof]" value="{{ $item['proof'] }}">
            @endif
        @endforeach
        @if(collect($preview)->whereNotNull('version')->isNotEmpty())
            <label>Internal note for all selected reports<textarea name="note" required maxlength="240" rows="3"></textarea></label>
            <div><button class="button button-primary">Resolve selected reports</button></div>
        @endif
    </form>
</x-layouts.admin>
