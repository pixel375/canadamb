<x-layouts.site :title="'Message '.$subject">
    <div class="container page-shell message-shell">
        <a class="text-link" href="{{ $back }}">Back to {{ strtolower($module) }}</a>
        <div class="page-intro">
            <div><p class="section-label">{{ $module }} · {{ $area }}</p><h1>Message {{ $subject }}</h1></div>
        </div>
        <form class="panel form-stack" method="post" action="{{ $action }}" enctype="multipart/form-data">
            @csrf
            <x-message-fields label="Message"/>
            <div><button class="button button-primary">Send message</button></div>
        </form>
    </div>
</x-layouts.site>
