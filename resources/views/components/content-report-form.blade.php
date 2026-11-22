@props(['type', 'target', 'label', 'author' => null])
@if(auth()->user()?->hasVerifiedEmail() && ($author === null || $author !== auth()->id()))
    <details class="form-stack mt-3">
        <summary class="text-link">Report {{ $label }}</summary>
        <form method="post" action="{{ route('content.reports.store', compact('type', 'target'), false) }}" class="form-stack">
            @csrf
            <label>Why are you reporting this {{ $label }}?
                <textarea name="reason" required minlength="8" maxlength="500" rows="3"></textarea>
            </label>
            <div><button class="button button-outline">Submit report</button></div>
        </form>
    </details>
@endif
