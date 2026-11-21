@php
    [$title, $action] = match ($mode) {
        'register' => ['Create an account', '/register'],
        'forgot' => ['Reset your password', '/forgot-password'],
        'reset' => ['Choose a new password', '/reset-password'],
        'verify' => ['Verify your email', '/email/verification-notification'],
        default => ['Sign in', '/login'],
    };
    $name = old('name');
    $email = old('email', request()->query->all()['email'] ?? null);
    $continuation = old('continuation', request()->query->all()['continuation'] ?? null);
    $continuation = is_string($continuation) && preg_match('/^[a-zA-Z0-9]{40}$/D', $continuation) ? $continuation : null;
@endphp
<x-layouts.site :title="$title">
    <div class="container page-shell auth-shell">
        <h1>{{ $title }}</h1>
        @if(session('status'))<p class="panel" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())
            <div class="panel" role="alert">
                <p>Check the following details and try again.</p>
                <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </div>
        @endif
        @if(config('network.prelaunch') && in_array($mode, ['verify', 'forgot'], true))
            <div class="panel form-stack">
                @if($mode === 'verify')
                    <p>Prelaunch does not send verification email. Ask the administrator coordinating your test to verify your account, then check again.</p>
                    <a class="button button-primary" href="/email/verify">Check verification</a>
                @else
                    <p>Password reset email is unavailable during prelaunch. Contact the administrator coordinating your test if you cannot sign in.</p>
                @endif
            </div>
        @else
        <form class="panel form-stack" method="post" action="{{ $action }}">
            @csrf
            @if($continuation && in_array($mode, ['login', 'register'], true))<input type="hidden" name="continuation" value="{{ $continuation }}">@endif
            @if($mode === 'register' && $registrationCommunity)<input type="hidden" name="community" value="{{ $registrationCommunity }}">@endif
            @if($mode === 'register' && config('network.prelaunch'))
                <p>Prelaunch does not send verification email. Your testing administrator will review your account before you can post or use verified features.</p>
            @endif
            @if($mode === 'register')<label>Name<input name="name" value="{{ is_string($name) ? $name : '' }}" required maxlength="80" autocomplete="name"></label>@endif
            @if($mode !== 'verify')<label>Email<input type="email" name="email" value="{{ is_string($email) ? $email : '' }}" required maxlength="254" autocomplete="email"></label>@endif
            @if($mode === 'reset')<input type="hidden" name="token" value="{{ $token }}">@endif
            @if(in_array($mode, ['login', 'register', 'reset'], true))<label>Password<input type="password" name="password" required autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}" @if($mode !== 'login') minlength="12" aria-describedby="password-rules" @endif>@if($mode !== 'login')<small id="password-rules" class="field-note">Use at least 12 characters, including a letter and a number.</small>@endif</label>@endif
            @if(in_array($mode, ['register', 'reset'], true))<label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>@endif
            @if($mode === 'verify')<p>Open the verification link in your email. You can request a new link here.</p>@endif
            <div><button class="button button-primary">{{ match($mode) { 'forgot' => 'Send reset link', 'verify' => 'Resend verification link', default => $title } }}</button></div>
        </form>
        @endif
        @if($mode === 'login')
            <div class="inline-actions">@if(app(\App\Support\NetworkSettings::class)->registrationAllowed())<a class="text-link" href="{{ $continuation ? '/register?continuation='.$continuation : '/register' }}">Create account</a>@endif<a class="text-link" href="/forgot-password">Forgot password?</a></div>
        @elseif($mode !== 'verify')<a class="text-link" href="/login">Back to sign in</a>
        @else<form action="/account/logout" method="post">@csrf<button class="button button-outline">Sign out</button></form>@endif
    </div>
</x-layouts.site>
