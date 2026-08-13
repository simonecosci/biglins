<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['en', 'it', 'es'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        if ($request->user() !== null) {
            return $request->user()->locale;
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);

        return $preferred ?? config('app.locale');
    }
}
