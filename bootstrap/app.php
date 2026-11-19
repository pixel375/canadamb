<?php

use App\Http\Middleware\EnforceSessionVersion;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveSite;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([ResolveSite::class, SecurityHeaders::class]);
        $middleware->web(append: [EnforceSessionVersion::class]);
        $middleware->alias(['verified' => RequireVerifiedEmail::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->attributes->get('site')?->isNetwork() ? '/login' : '/auth/start');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
