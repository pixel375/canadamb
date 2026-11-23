<x-layouts.site :title="'Report ownership concern · '.$business->name">
    <div class="container page-shell narrow">
        <a class="text-link" href="/businesses/{{ $business->slug }}">Back to business listing</a>
        <div class="page-intro"><div><h1>Report an ownership concern</h1><p>{{ $business->name }}</p></div></div>
        <form class="panel form-stack" method="post" action="/businesses/{{ $business->slug }}/ownership-dispute">
            @csrf
            <p>Use this only when you believe the current business ownership designation may be incorrect. The report is private and reviewed by a network administrator. It does not give you ownership.</p>
            <label>Evidence for an independent reviewer
                <textarea name="evidence" minlength="30" maxlength="2400" rows="6" required>{{ old('evidence') }}</textarea>
            </label>
            <p class="field-note">Describe facts the reviewer can independently verify. Do not include passwords, financial account numbers or private documents.</p>
            <button class="button button-primary">Send private ownership report</button>
        </form>
    </div>
</x-layouts.site>
