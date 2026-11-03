<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();
        $locale = $request->user('sanctum')?->preferredLocale()
            ?? ($request->hasHeader('Accept-Language') ? $request->getPreferredLanguage(['en', 'es']) : config('app.locale'));
        app()->setLocale($locale);

        try {
            return $next($request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
