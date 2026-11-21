<x-layouts.site title="Contact us">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Contact us</h1><p>Questions or feedback about {{ $site->name }}?</p></div></div>
        <section class="panel form-stack">
            @if($site->contact_email)
                <p>You can contact the {{ $site->name }} team at:</p>
                <a class="text-link" href="mailto:{{ $site->contact_email }}">{{ $site->contact_email }}</a>
            @else
                <p>A public contact address for this community portal is being configured during prelaunch testing.</p>
            @endif
            <p>For a Marketplace listing concern, use the report option on that listing. Avoid including passwords or sensitive personal information in reports.</p>
            <a class="text-link" href="/about">Learn about CanadaMB</a>
        </section>
    </div>
</x-layouts.site>
