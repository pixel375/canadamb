<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = Domain::with('site.location')->where('host', $request->getHost())->first();
        abort_unless($domain, 404);
        $site = $domain->site;
        abort_if($site->status === 'maintenance', 503, 'This community is temporarily offline.');
        abort_unless($site->status === 'active', 404);

        if ($request->is('admin', 'admin/*', 'oauth/*', 'register', 'forgot-password', 'reset-password', 'reset-password/*', 'email/verify', 'email/verify/*', 'email/verification-notification', 'user/*', 'two-factor-challenge') || ($request->is('login') && $request->isMethod('POST'))) {
            abort_unless($site->isNetwork(), 404);
        }

        $site->setRelation('domain', $domain);
        $request->attributes->set('site', $site);

        return $next($request);
    }
}
