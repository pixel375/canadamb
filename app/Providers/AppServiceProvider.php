<?php

namespace App\Providers;

use App\Actions\Fortify\CreateUser;
use App\Actions\Fortify\ResetPassword;
use App\Http\Responses\LoginResponse as CentralLoginResponse;
use App\Http\Responses\PasswordResetFailed;
use App\Http\Responses\PasswordResetRequested;
use App\Http\Responses\RegistrationResponse;
use App\Models\CommunityOAuthClient;
use App\Models\User;
use App\Support\Identity;
use App\Support\NetworkSettings;
use App\Support\NotificationFeed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword as ResetNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::useClientModel(CommunityOAuthClient::class);
    }

    public function boot(): void
    {
        TrustProxies::at(config('network.trusted_proxies', []));
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Gate::define('manage-network', fn (User $user) => in_array($user->role, ['network_admin', 'super_admin'], true));
        Gate::define('manage-theme', fn (User $user) => $user->role === 'super_admin');
        Gate::define('manage-news', fn (User $user) => in_array($user->role, ['news_editor', 'network_admin', 'super_admin'], true));
        Paginator::defaultView('components.pagination');

        View::composer('components.layouts.site', function ($view) {
            $site = request()->attributes->get('site');
            if ($site) {
                $viewer = request()->user();
                $view->with([
                    'site' => $site,
                    'networkSettings' => app(NetworkSettings::class)->forSite($site),
                    'unreadNotifications' => $viewer?->hasVerifiedEmail() ? app(NotificationFeed::class)->unread($site, $viewer->id) : 0,
                ]);
            }
        });

        Event::listen(Login::class, function (Login $event) {
            if (request()->hasSession()) {
                request()->session()->put([
                    'session_version' => $event->user->session_version,
                    'session_host' => request()->getHost(),
                ]);
            }
        });

        Fortify::createUsersUsing(CreateUser::class);
        Fortify::resetUserPasswordsUsing(ResetPassword::class);
        Fortify::loginView(fn (Request $request) => $request->attributes->get('site')->isNetwork()
            ? view('auth.form', ['mode' => 'login']) : redirect('/auth/start'));
        $this->app->bind(RegisterResponse::class, RegistrationResponse::class);
        $this->app->bind(LoginResponse::class, CentralLoginResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetRequested::class);
        $this->app->bind(FailedPasswordResetResponse::class, PasswordResetFailed::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetRequested::class);
        Fortify::registerView(fn (Request $request) => view('auth.form', [
            'mode' => 'register',
            'registrationCommunity' => Identity::registrationCommunity($request->query->all()['community'] ?? old('community'))?->id,
        ]));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.form', ['mode' => 'forgot']));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.form', ['mode' => 'reset', 'token' => $request->route('token')]));
        Fortify::verifyEmailView(fn () => view('auth.form', ['mode' => 'verify']));
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by((is_string($email) ? Str::lower($email) : '').'|'.$request->ip());
        });
        RateLimiter::for('identity-requests', fn (Request $request) => Limit::perMinute($request->is('login') ? 20 : 6)
            ->by($request->route()->getName().'|'.$request->ip()));
        RateLimiter::for('api-network', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
        Passport::tokensCan(['community-sign-in' => 'Sign in to an active CanadaMB community portal']);
        Passport::tokensExpireIn(now()->addMinutes(5));
        Passport::refreshTokensExpireIn(now()->addMinutes(5));
        Passport::authorizationView('auth.consent');

        // Identity links use the central origin even when initiated from a local site.
        VerifyEmail::createUrlUsing(fn (User $user) => URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        ));
        ResetNotification::createUrlUsing(fn (User $user, string $token) => rtrim(config('app.url'), '/').'/reset-password/'.$token.'?'.http_build_query(['email' => $user->email]));
    }
}
