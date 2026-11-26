@props(['changes', 'target'])
@if($changes)
    <div class="form-stack">
        @foreach($changes as $change)
            <div class="admin-configuration-change">
                <strong>{{ $change['label'] }}</strong>
                <div class="form-grid">
                    <div><p class="field-note">Currently published</p><p>{{ $change['before'] }}</p></div>
                    <div><p class="field-note">{{ $target }}</p><p>{{ $change['after'] }}</p></div>
                </div>
            </div>
        @endforeach
    </div>
@else
    <p>No differences detected in the reviewed settings.</p>
@endif
