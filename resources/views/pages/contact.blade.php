<?php
    $topics = ['general' => 'General feedback', 'safety' => 'Safety concern', 'community' => 'Discussion or event concern', 'business' => 'Business or job concern', 'news' => 'News correction', 'privacy' => 'Privacy or account request'];
    $requested = request()->query('topic');
    $selected = is_string($requested) && isset($topics[$requested]) ? $requested : 'general';
    $email = $site->contact_email ?: \App\Support\NetworkUrl::main()->contact_email;
?>
<x-layouts.site title="Contact us">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><h1>Contact us</h1><p>Questions, corrections or feedback about {{ $site->name }}?</p></div></div>
        <section class="panel form-stack">
            <h2>Choose a topic</h2>
            <form method="get" action="/contact" class="form-stack"><label>What can we help with?<select name="topic">@foreach($topics as $key => $label)<option value="{{ $key }}" @selected($key === $selected)>{{ $label }}</option>@endforeach</select></label><div><button class="button button-outline">Choose topic</button></div></form>
            @if($email)
                <h2>{{ $topics[$selected] }}</h2>
                <p>Include the relevant page address or record reference, a brief description and any useful non-sensitive context.</p>
                <a class="button button-primary" href="mailto:{{ $email }}?subject={{ rawurlencode('CanadaMB: '.$topics[$selected]) }}">Open email app</a>
                <p class="field-note">{{ $email }} · This opens your email app; nothing is submitted through this page.</p>
            @else
                <p class="notice">A public contact address is not configured yet. Contact the administrator coordinating your prelaunch testing.</p>
            @endif
            <p>Never include passwords, verification codes, payment information or unnecessary identity documents.</p>
        </section>
        <section class="panel form-stack mt-4"><h2>Other ways to get help</h2><p>For a Marketplace concern, report the listing directly. For unwanted private messages, block the member from the conversation options.</p><div class="inline-actions"><a class="text-link" href="/safety">Safety guidance</a><a class="text-link" href="/privacy-overview">Account privacy</a>@auth<a class="text-link" href="/account/listing-reports">My listing reports</a>@endauth</div></section>
    </div>
</x-layouts.site>
