<x-layouts.admin title="Bulk review preview">
    <div class="page-intro"><div><h1>Bulk review preview</h1><p>Review the selected submissions before making a decision. Changed submissions will be skipped.</p></div></div>
    <p><a class="text-link" href="{{ route('moderation.index', ['type' => $type]) }}">Back to the queue</a></p>
    <form method="post" action="{{ route('moderation.bulk') }}" class="panel form-stack">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <ul class="notification-list">
            @foreach($items as $entry)
                <li class="item-row">
                    @if($entry['version'])
                        <a class="text-link" href="{{ route('moderation.items.show', ['type' => $type, 'item' => $entry['id']]) }}">#{{ $entry['id'] }} · {{ $entry['title'] }}</a>
                        <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $entry['id'] }}">
                        <input type="hidden" name="items[{{ $loop->index }}][version]" value="{{ $entry['version'] }}">
                        <input type="hidden" name="items[{{ $loop->index }}][proof]" value="{{ $entry['proof'] }}">
                    @else
                        <span>#{{ $entry['id'] }} · {{ $entry['title'] ?? 'No longer available' }} · Review individually</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if(collect($items)->pluck('version')->filter()->isNotEmpty())
            <label>Reason shown to submitters if rejected<textarea name="reason" maxlength="240" rows="2"></textarea></label>
            <label>Internal reviewer notes @if(in_array($type, ['business', 'business-update'], true)) (required) @endif
                <textarea name="notes" maxlength="1200" @if(in_array($type, ['business', 'business-update'], true)) required minlength="8" @endif rows="2"></textarea>
            </label>
            <div class="inline-actions">
                <button class="button button-primary" name="action" value="approve">Approve selected</button>
                <button class="button button-outline" name="action" value="reject">Reject selected</button>
            </div>
        @endif
    </form>
</x-layouts.admin>
