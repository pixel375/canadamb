@php
    [$title, $action] = match ($mode) {
        'register' => ['Create an account', '/register'],
        'forgot' => ['Reset your password', '/forgot-password'],
        'reset' => ['Choose a new password', '/reset-password'],
        'verify' => ['Verify your email', '/email/verification-notification'],
        default => ['Sign in', '/login'],
    };
@endphp
<x-layouts.site :title="$title">
    <div class="container page-shell auth-shell">
        <h1>{{ $title }}</h1>
        <form class="panel form-stack" method="post" action="{{ $action }}">
            @csrf
            @if($mode === 'register')<label>Name<input name="name" value="{{ old('name') }}" required maxlength="80" autocomplete="name"></label>@endif
            @if($mode !== 'verify')<label>Email<input type="email" name="email" value="{{ old('email', request()->string('email')->value()) }}" required maxlength="254" autocomplete="email"></label>@endif
            @if($mode === 'reset')<input type="hidden" name="token" value="{{ $token }}">@endif
            @if(in_array($mode, ['login', 'register', 'reset'], true))<label>Password<input type="password" name="password" required autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}">@if($mode !== 'login')<small class="field-note">Use at least 12 characters.</small>@endif</label>@endif
            @if(in_array($mode, ['register', 'reset'], true))<label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>@endif
            @if($mode === 'verify')<p>Open the verification link in your email. You can request a new link here.</p>@endif
            <div><button class="button button-primary">{{ match($mode) { 'forgot' => 'Send reset link', 'verify' => 'Resend verification link', default => $title } }}</button></div>
        </form>
        @if($mode === 'login')
            <div class="inline-actions"><a class="text-link" href="/register">Create an account</a><a class="text-link" href="/forgot-password">Forgot password?</a></div>
        @elseif($mode !== 'verify')<a class="text-link" href="/login">Back to sign in</a>
        @else<form action="/account/logout" method="post">@csrf<button class="button button-outline">Sign out</button></form>@endif
    </div>
</x-layouts.site>
