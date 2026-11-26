<?php

use App\Http\Middleware\EnforceSessionVersion;
use App\Http\Middleware\ProtectIdentityRequests;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveSite;
use App\Http\Middleware\RestrictPrelaunch;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Identity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([RestrictPrelaunch::class, ResolveSite::class, SecurityHeaders::class]);
        $middleware->web(append: [EnforceSessionVersion::class, ProtectIdentityRequests::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceSessionVersion::class);
        $middleware->alias(['verified' => RequireVerifiedEmail::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->attributes->get('site')?->isNetwork() ? '/login' : '/auth/start');
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('login') && $request->attributes->get('site')?->isNetwork()
            ? Identity::takeCentralAuthorization($request) ?? '/'
            : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['weather_api_key']);
        $exceptions->render(function (AuthenticationException $error, Request $request) {
            if ($request->routeIs('passport.authorizations.authorize') && ! $request->expectsJson()
                && ($ticket = Identity::rememberCentralAuthorization($request))) {
                return redirect('/login?continuation='.$ticket);
            }
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
