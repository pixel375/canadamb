@php($viewer = request()->user())
<section class="panel seller-panel" id="seller">
    <h2>Seller</h2>
    <p class="seller-name"><a class="text-link" href="/users/{{ $item->seller->id }}">{{ $item->seller->name }}</a></p>
    <p class="seller-since">Member since {{ $item->seller->created_at->format('F Y') }}</p>
    @if($viewer?->id === $item->user_id)
        <p class="field-note">This is your listing.</p>
        @if($viewer->hasVerifiedEmail())
            <a class="button button-primary seller-action" href="/account/listings/{{ $item->id }}/edit">Edit listing</a>
            <a class="button button-outline seller-action" href="/account/listings">Manage my listings</a>
            <form method="post" action="/account/listings/{{ $item->id }}/status">
                @csrf
                <input type="hidden" name="status" value="sold">
                <button class="button button-outline seller-action" type="submit">Mark as sold</button>
            </form>
        @else
            <a class="button button-primary seller-action" href="/account/listings">Verify your email to manage this listing</a>
        @endif
    @elseif($viewer?->hasVerifiedEmail())
        <form id="message-seller" class="form-stack seller-contact" method="post" action="/marketplace/{{ $item->id }}/message">
            @csrf
            <label>Message the seller<textarea name="body" maxlength="2000" rows="3" required placeholder="Hi, is this still available?">{{ is_string(old('body')) ? old('body') : '' }}</textarea></label>
            <button class="button button-primary" type="submit">Send message</button>
        </form>
        <form method="post" action="/marketplace/{{ $item->id }}/save">
            @csrf
            @if($isSaved)@method('DELETE')@endif
            <input type="hidden" name="return_to" value="listing">
            <button class="button button-outline seller-action" type="submit">{{ $isSaved ? 'Unsave listing' : 'Save listing' }}</button>
        </form>
        @if($hasReported)
            <p class="field-note seller-report">You have already reported this listing.</p>
        @else
            <details class="seller-report" @if($errors->has('reason')) open @endif>
                <summary>Report listing</summary>
                <form class="form-stack mt-2" method="post" action="/marketplace/{{ $item->id }}/report">
                    @csrf
                    <label>Reason<textarea name="reason" minlength="8" maxlength="500" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea></label>
                    <button class="button button-outline" type="submit">Submit report</button>
                </form>
            </details>
        @endif
    @else
        <p class="field-note">Contact the seller through CanadaMB messages.</p>
        <a class="button button-primary seller-action" href="/marketplace/{{ $item->id }}/contact">{{ $viewer ? 'Verify your email to message' : 'Sign in to message' }}</a>
    @endif
</section>
