<x-layouts.admin title="Reported conversation evidence">
    <div class="page-intro">
        <div><a class="text-link" href="{{ route('moderation.reports.show', ['type' => 'conversation', 'report' => $report]) }}">Return to report #{{ $report }}</a><h1>Reported evidence</h1></div>
    </div>
    <section class="panel form-stack">
        <div><h2>Report submission</h2><p class="prose-text">{{ $reason }}</p></div>
        @if($message)
            <div>
                <h2>Reported message</h2>
                <p>Message #{{ $message->id }} · {{ $message->created_at }}</p>
                <p class="prose-text">{{ $message->body }}</p>
            </div>
        @else
            <p>No reported message is available.</p>
        @endif
    </section>
</x-layouts.admin>
