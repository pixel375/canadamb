<x-layouts.admin title="Restricted reported evidence">
    <div class="page-intro"><div><h1>Reported message #{{ $report }}</h1><p>Access recorded. Only the individually reported message is displayed.</p></div></div>
    <section class="panel form-stack"><p class="prose-text preserve-lines">{{ $body }}</p><p class="field-note">Do not copy private content into review notes or request unrelated messages.</p><a class="button button-outline" href="/admin/content-reports?reference={{ $report }}">Return to report</a></section>
</x-layouts.admin>
