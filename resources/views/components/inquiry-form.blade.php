@props(['path', 'label'])
<section class="panel form-stack">
    <h2>{{ $label }}</h2>
    <details><summary>Send a private inquiry</summary>
        <form class="form-stack mt-2" method="post" action="{{ $path }}">@csrf
            <label>Your message<textarea name="body" rows="4" required maxlength="2000"></textarea></label>
            <p class="field-note">Sent to the current business owner’s personal account. Do not include sensitive documents, payment details or identity numbers. This is not a job application.</p>
            <button class="button button-primary">Send inquiry</button>
        </form>
    </details>
</section>
