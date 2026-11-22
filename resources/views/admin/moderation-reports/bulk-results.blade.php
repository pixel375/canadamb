<x-layouts.admin title="Report batch results">
    <div class="page-intro">
        <div><a class="text-link" href="{{ route('moderation.reports.index', ['type' => $type]) }}">Abuse reports</a><h1>Report batch results</h1></div>
    </div>
    <section class="panel form-stack">
        <h2>{{ collect($results)->where('success', true)->count() }} of {{ count($results) }} reports resolved</h2>
        <ul>
            @foreach($results as $item)
                <li>{{ $item['item'] }} · {{ $item['result'] }}</li>
            @endforeach
        </ul>
    </section>
</x-layouts.admin>
