<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ApiLocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        $acceptLanguage = $request->header('Accept-Language', 'en');
        
        // Parse Accept-Language header to extract the first valid locale
        // Accept-Language format: "en_US,en;q=0.9,ar;q=0.8" or "en" or "en-US"
        $locale = $this->parseAcceptLanguage($acceptLanguage);
        
        // Validate locale against supported locales from config
        $supportedLocales = array_keys(config('laravellocalization.supportedLocales', ['en' => [], 'ar' => []]));
        if (!in_array($locale, $supportedLocales)) {
            $locale = config('app.locale', 'en'); // Default to app locale if not supported
        }
        
        App::setLocale($locale);
        return $next($request);
    }

    /**
     * Parse Accept-Language header and extract the first valid locale code
     *
     * @param string $acceptLanguage
     * @return string
     */
    private function parseAcceptLanguage(string $acceptLanguage): string
    {
        // Remove whitespace
        $acceptLanguage = trim($acceptLanguage);
        
        // Split by comma to get language preferences
        $languages = explode(',', $acceptLanguage);
        
        if (empty($languages)) {
            return 'en';
        }
        
        // Get the first language preference
        $firstLanguage = trim($languages[0]);
        
        // Remove quality value if present (e.g., "en;q=0.9" -> "en")
        if (strpos($firstLanguage, ';') !== false) {
            $firstLanguage = trim(explode(';', $firstLanguage)[0]);
        }
        
        // Extract base locale (e.g., "en_US" -> "en", "en-US" -> "en")
        // Handle both underscore and hyphen separators
        $parts = preg_split('/[_-]/', $firstLanguage);
        $baseLocale = strtolower($parts[0] ?? 'en');
        
        // Get supported locales from config
        $supportedLocales = array_keys(config('laravellocalization.supportedLocales', []));
        
        // If base locale is supported, return it
        if (in_array($baseLocale, $supportedLocales)) {
            return $baseLocale;
        }
        
        // Check if Arabic is available (even if not in config, it might be in lang directory)
        if ($baseLocale === 'ar' && is_dir(lang_path('ar'))) {
            return 'ar';
        }
        
        // Default to English
        return 'en';
    }
}
