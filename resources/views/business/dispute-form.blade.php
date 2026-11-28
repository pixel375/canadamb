<x-layouts.site title="Business ownership dispute">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="{{ \App\Support\LocationScope::detailUrl('/businesses/'.$item->slug) }}">Back to business</a><h1>Ownership dispute</h1><p>{{ $item->name }}</p></div></div>
        <form class="panel form-stack" method="post" action="{{ \App\Support\LocationScope::detailUrl('/businesses/'.$item->slug.'/dispute') }}">@csrf
            <p>Independent reviewers will assess your concern. Submission does not change ownership or grant access.</p>
            <label>Contact email<input type="email" name="contact_email" value="{{ old('contact_email') }}" maxlength="254" required></label>
            <label>Explain the ownership concern<textarea name="explanation" minlength="30" maxlength="2000" rows="6" required>{{ old('explanation') }}</textarea></label>
            <p class="field-note">Describe evidence you can provide privately. Do not include passwords, identity numbers or confidential documents.</p>
            <button class="button button-primary">Submit for review</button>
        </form>
    </div>
</x-layouts.site>
