<x-layouts.site title="Report content">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/safety">Safety guidance</a><h1>Report {{ strtolower(\App\Support\ContentReports::TYPES[$type]) }}</h1><p>Reference #{{ $target }}</p></div></div>
        <section class="panel form-stack"><h2>{{ $item->title ?? 'Discussion reply' }}</h2><p class="prose-text">{{ \Illuminate\Support\Str::limit($item->body, 250) }}</p>
            @if($reported)<p>You have already reported this content.</p><a class="button button-outline" href="/account/content-reports">My content reports</a>@else
            <p>Reports are private and reviewed by authorized moderators. Do not include passwords or unnecessary personal details.</p>
            <form method="post" action="/account/report/{{ $type }}/{{ $target }}" class="form-stack">@csrf
                <label>Reason<select name="reason" required><option value="">Choose a reason</option>@foreach(\App\Support\ContentReports::REASONS as $key => $label)<option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label>Explain the concern<textarea name="explanation" required minlength="10" maxlength="1000" data-character-count>{{ old('explanation') }}</textarea><small class="field-note" data-character-feedback></small></label>
                <button class="button button-primary">Submit report</button>
            </form>@endif
        </section>
    </div>
</x-layouts.site>
