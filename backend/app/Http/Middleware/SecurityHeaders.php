<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        /** @var Response $response */
        $response = $next($request);

        // Browser ne sme da pogađa MIME tip (sprečava npr. izvršavanje
        // uploadovane slike kao skripte)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Zabrana ugrađivanja aplikacije u <iframe> — zaštita od clickjacking-a
        $response->headers->set('X-Frame-Options', 'DENY');

        // Ka drugim sajtovima se ne šalje puna putanja (ni query string)
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Aplikaciji ne trebaju kamera, mikrofon ni lokacija
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS samo preko HTTPS-a — na HTTP-u bi header bio ignorisan.
        // 1 godina + preload: browser pamti da sajt SME samo preko HTTPS-a
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Content-Security-Policy se šalje samo na HTML odgovorima — na JSON/API
        // odgovorima nema DOM-a pa nema šta da štiti, a header bi bio suvišan.
        // CSP je poslednja linija odbrane od XSS-a: čak i da neeskejpovan sadržaj
        // dospe u stranicu, browser ne izvršava skripte sa neодobrenih izvora.
        $contentType = (string) $response->headers->get('Content-Type');
        if (str_contains($contentType, 'text/html')) {
            $response->headers->set('Content-Security-Policy', $this->cspPolicy($request));
        }

        return $response;
    }

    private function cspPolicy(Request $request): string
    {
        // 'unsafe-inline' za script/style je nužno jer Blade šabloni sadrže
        // inline <script> i style blokove. Rizik je nizak jer sav korisnički
        // sadržaj prolazi kroz Blade auto-escaping ({{ }}); CSP i dalje blokira
        // UČITAVANJE skripti sa proizvoljnih spoljnih domena.
        $directives = [
            "default-src 'self'",
            // Font Awesome se od Faze 3 servira lokalno (public/css/fontawesome.css
            // + public/webfonts), pa kit domeni ispadaju iz politike.
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data:",
            "connect-src 'self'",
            // Blokira <base> otimanje i slanje formi na tuđe domene
            "base-uri 'self'",
            "form-action 'self'",
            // Dvostruka zaštita od clickjacking-a (uz X-Frame-Options)
            "frame-ancestors 'none'",
            // Nema Flash/Java apleta ni <object>/<embed>
            "object-src 'none'",
        ];

        // Na HTTPS-u tera browser da i slučajne http:// resurse povuče preko HTTPS-a
        if ($request->secure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
